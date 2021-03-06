<?php
/**
 * User interface for the difference engine.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup DifferenceEngine
 */

/**
 * @todo document
 * @ingroup DifferenceEngine
 */
class DifferenceEngine extends ContextSource {
	/**
	 * Constant to indicate diff cache compatibility.
	 * Bump this when changing the diff formatting in a way that
	 * fixes important bugs or such to force cached diff views to
	 * clear.
	 */
	const CACHE_VERSION ='1.11a';

	/**#@+
	 * @private
	 */
	public $oldId;
	public $newId;
	private $oldTags;
	private $newTags;
	/**
	 * @var Content
	 */
	public $oldContent;
	/**
	 * @var Content
	 */
	public $newContent;
	protected $diffLang;

	/**
	 * @var Title
	 */
	public $oldPage;
	/**
	 * @var Title
	 */
	public $newPage;

	/**
	 * @var Revision
	 */
	public $oldRev;
	/**
	 * @var Revision
	 */
	public $newRev;
	private $revisionsIdsLoaded = false; // Have the revisions IDs been loaded
	public $revisionsLoaded = false; // Have the revisions been loaded
	public $textLoaded = 0; // How many text blobs have been loaded, 0, 1 or 2?
	public $cacheHit = false; // Was the diff fetched from cache?

	/**
	 * Set this to true to add debug info to the HTML output.
	 * Warning: this may cause RSS readers to spuriously mark articles as "new"
	 * (bug 20601)
	 */
	public $enableDebugComment = false;

	// If true, line X is not displayed when X is 1, for example to increase
	// readability and conserve space with many small diffs.
	protected $reducedLineNumbers = false;

	// Link to action=markpatrolled
	protected $markPatrolledLink = null;

	protected $unhide = false; # show rev_deleted content if allowed
	private $refreshCache;
	/**#@-*/

	/**
	 * Constructor
	 * @param $context IContextSource context to use, anything else will be ignored
	 * @param $old Integer old ID we want to show and diff with.
	 * @param $new String|int either revision ID or 'prev' or 'next'. Default: 0.
	 * @param $rcid Integer Deprecated, no longer used!
	 * @param $refreshCache boolean If set, refreshes the diff cache
	 * @param $unhide boolean If set, allow viewing deleted revs
	 */
	function __construct( $context = null, $old = 0, $new = 0, $rcid = 0,
		$refreshCache = false, $unhide = false
	) {
		if ( $context instanceof IContextSource ) {
			$this->setContext( $context );
		}

		wfDebug( "DifferenceEngine old '$old' new '$new' rcid '$rcid'\n" );

		$this->oldId = $old;
		$this->newId = $new;
		$this->refreshCache = $refreshCache;
		$this->unhide = $unhide;
	}

	/**
	 * @param $value bool
	 */
	function setReducedLineNumbers( $value = true ) {
		$this->reducedLineNumbers = $value;
	}

	/**
	 * @return Language
	 */
	function getDiffLang() {
		if ( $this->diffLang === null ) {
			# Default language in which the diff text is written.
			$this->diffLang = $this->getTitle()->getPageLanguage();
		}
		return $this->diffLang;
	}

	/**
	 * @return bool
	 */
	function wasCacheHit() {
		return $this->cacheHit;
	}

	/**
	 * @return int
	 */
	function getOldid() {
		$this->loadRevisionIds();
		return $this->oldId;
	}

	/**
	 * @return Bool|int
	 */
	function getNewid() {
		$this->loadRevisionIds();
		return $this->newId;
	}

	/**
	 * Look up a special:Undelete link to the given deleted revision id,
	 * as a workaround for being unable to load deleted diffs in currently.
	 *
	 * @param int $id revision ID
	 * @return mixed URL or false
	 */
	function deletedLink( $id ) {
		if ( $this->getUser()->isAllowed( 'deletedhistory' ) ) {
			$dbr = wfGetDB( DB_SLAVE );
			$row = $dbr->selectRow( 'archive', '*',
				array( 'ar_rev_id' => $id ),
				__METHOD__ );
			if ( $row ) {
				$rev = Revision::newFromArchiveRow( $row );
				$title = Title::makeTitleSafe( $row->ar_namespace, $row->ar_title );
				return SpecialPage::getTitleFor( 'Undelete' )->getFullURL( array(
					'target' => $title->getPrefixedText(),
					'timestamp' => $rev->getTimestamp()
				));
			}
		}
		return false;
	}

	/**
	 * Build a wikitext link toward a deleted revision, if viewable.
	 *
	 * @param int $id revision ID
	 * @return string wikitext fragment
	 */
	function deletedIdMarker( $id ) {
		$link = $this->deletedLink( $id );
		if ( $link ) {
			return "[$link $id]";
		} else {
			return $id;
		}
	}

	private function showMissingRevision() {
		$out = $this->getOutput();

		$missing = array();
		if ( $this->oldRev === null ||
			( $this->oldRev && $this->oldContent === null )
		) {
			$missing[] = $this->deletedIdMarker( $this->oldId );
		}
		if ( $this->newRev === null ||
			( $this->newRev && $this->newContent === null )
		) {
			$missing[] = $this->deletedIdMarker( $this->newId );
		}

		$out->setPageTitle( $this->msg( 'errorpagetitle' ) );
		$out->addWikiMsg( 'difference-missing-revision',
			$this->getLanguage()->listToText( $missing ), count( $missing ) );
	}

	function showDiffPage( $diffOnly = false ) {
		wfProfileIn( __METHOD__ );

		# Allow frames except in certain special cases
		$out = $this->getOutput();
		$out->allowClickjacking();
		$out->setRobotPolicy( 'noindex,nofollow' );

		if ( !$this->loadRevisionData() ) {
			$this->showMissingRevision();
			wfProfileOut( __METHOD__ );
			return;
		}

		$user = $this->getUser();
		$permErrors = $this->newPage->getUserPermissionsErrors( 'read', $user );
		if ( $this->oldPage ) { # oldPage might not be set, see below.
			$permErrors = wfMergeErrorArrays( $permErrors,
				$this->oldPage->getUserPermissionsErrors( 'read', $user ) );
		}
		if ( count( $permErrors ) ) {
			wfProfileOut( __METHOD__ );
			throw new PermissionsError( 'read', $permErrors );
		}

		$rollback = '';

		$query = array();
		# Carry over 'diffonly' param via navigation links
		if ( $diffOnly != $user->getBoolOption( 'diffonly' ) ) {
			$query['diffonly'] = $diffOnly;
		}
		# Cascade unhide param in links for easy deletion browsing
		if ( $this->unhide ) {
			$query['unhide'] = 1;
		}

		# Check if one of the revisions is deleted/suppressed
		$deleted = $suppressed = false;
		$allowed = $this->newRev->userCan( Revision::DELETED_TEXT, $user );

		$revisionTools = array();

		# oldRev is false if the difference engine is called with a "vague" query for
		# a diff between a version V and its previous version V' AND the version V
		# is the first version of that article. In that case, V' does not exist.
		if ( $this->oldRev === false ) {
			$out->setPageTitle( $this->msg( 'difference-title', $this->newPage->getPrefixedText() ) );
			$samePage = true;
			$oldHeader = '';
		} else {
			wfRunHooks( 'DiffViewHeader', array( $this, $this->oldRev, $this->newRev ) );

			if ( $this->newPage->equals( $this->oldPage ) ) {
				$out->setPageTitle( $this->msg( 'difference-title', $this->newPage->getPrefixedText() ) );
				$samePage = true;
			} else {
				$out->setPageTitle( $this->msg( 'difference-title-multipage',
					$this->oldPage->getPrefixedText(), $this->newPage->getPrefixedText() ) );
				$out->addSubtitle( $this->msg( 'difference-multipage' ) );
				$samePage = false;
			}

			if ( $samePage && $this->newPage->quickUserCan( 'edit', $user ) ) {
				if ( $this->newRev->isCurrent() && $this->newPage->userCan( 'rollback', $user ) ) {
					$rollbackLink = Linker::generateRollback( $this->newRev, $this->getContext() );
					if ( $rollbackLink ) {
						$out->preventClickjacking();
						$rollback = '&#160;&#160;&#160;' . $rollbackLink;
					}
				}

				if ( !$this->oldRev->isDeleted( Revision::DELETED_TEXT ) &&
					!$this->newRev->isDeleted( Revision::DELETED_TEXT )
				) {
					$undoLink = Html::element( 'a', array(
							'href' => $this->newPage->getLocalURL( array(
								'action' => 'edit',
								'undoafter' => $this->oldId,
								'undo' => $this->newId ) ),
							'title' => Linker::titleAttrib( 'undo' )
						),
						$this->msg( 'editundo' )->text()
					);
					$revisionTools[] = $undoLink;
				}
			}

			# Make "previous revision link"
			if ( $samePage && $this->oldRev->getPrevious() ) {
				$prevlink = Linker::linkKnown(
					$this->oldPage,
					$this->msg( 'previousdiff' )->escaped(),
					array( 'id' => 'differences-prevlink' ),
					array( 'diff' => 'prev', 'oldid' => $this->oldId ) + $query
				);
			} else {
				$prevlink = '&#160;';
			}

			if ( $this->oldRev->isMinor() ) {
				$oldminor = ChangesList::flag( 'minor' );
			} else {
				$oldminor = '';
			}

			$ldel = $this->revisionDeleteLink( $this->oldRev );
			$oldRevisionHeader = $this->getRevisionHeader( $this->oldRev, 'complete' );
			$oldChangeTags = ChangeTags::formatSummaryRow( $this->oldTags, 'diff' );

			$oldHeader = '<div id="mw-diff-otitle1"><strong>' . $oldRevisionHeader . '</strong></div>' .
				'<div id="mw-diff-otitle2">' .
					Linker::revUserTools( $this->oldRev, !$this->unhide ) . '</div>' .
				'<div id="mw-diff-otitle3">' . $oldminor .
					Linker::revComment( $this->oldRev, !$diffOnly, !$this->unhide ) . $ldel . '</div>' .
				'<div id="mw-diff-otitle5">' . $oldChangeTags[0] . '</div>' .
				'<div id="mw-diff-otitle4">' . $prevlink . '</div>';

			if ( $this->oldRev->isDeleted( Revision::DELETED_TEXT ) ) {
				$deleted = true; // old revisions text is hidden
				if ( $this->oldRev->isDeleted( Revision::DELETED_RESTRICTED ) ) {
					$suppressed = true; // also suppressed
				}
			}

			# Check if this user can see the revisions
			if ( !$this->oldRev->userCan( Revision::DELETED_TEXT, $user ) ) {
				$allowed = false;
			}
		}

		# Make "next revision link"
		# Skip next link on the top revision
		if ( $samePage && !$this->newRev->isCurrent() ) {
			$nextlink = Linker::linkKnown(
				$this->newPage,
				$this->msg( 'nextdiff' )->escaped(),
				array( 'id' => 'differences-nextlink' ),
				array( 'diff' => 'next', 'oldid' => $this->newId ) + $query
			);
		} else {
			$nextlink = '&#160;';
		}

		if ( $this->newRev->isMinor() ) {
			$newminor = ChangesList::flag( 'minor' );
		} else {
			$newminor = '';
		}

		# Handle RevisionDelete links...
		$rdel = $this->revisionDeleteLink( $this->newRev );

		# Allow extensions to define their own revision tools
		wfRunHooks( 'DiffRevisionTools', array( $this->newRev, &$revisionTools ) );
		$formattedRevisionTools = array();
		// Put each one in parentheses (poor man's button)
		foreach ( $revisionTools as $tool ) {
			$formattedRevisionTools[] = $this->msg( 'parentheses' )->rawParams( $tool )->escaped();
		}
		$newRevisionHeader = $this->getRevisionHeader( $this->newRev, 'complete' ) .
			' ' . implode( ' ', $formattedRevisionTools );
		$newChangeTags = ChangeTags::formatSummaryRow( $this->newTags, 'diff' );

		$newHeader = '<div id="mw-diff-ntitle1"><strong>' . $newRevisionHeader . '</strong></div>' .
			'<div id="mw-diff-ntitle2">' . Linker::revUserTools( $this->newRev, !$this->unhide ) .
				" $rollback</div>" .
			'<div id="mw-diff-ntitle3">' . $newminor .
				Linker::revComment( $this->newRev, !$diffOnly, !$this->unhide ) . $rdel . '</div>' .
			'<div id="mw-diff-ntitle5">' . $newChangeTags[0] . '</div>' .
			'<div id="mw-diff-ntitle4">' . $nextlink . $this->markPatrolledLink() . '</div>';

		if ( $this->newRev->isDeleted( Revision::DELETED_TEXT ) ) {
			$deleted = true; // new revisions text is hidden
			if ( $this->newRev->isDeleted( Revision::DELETED_RESTRICTED ) ) {
				$suppressed = true; // also suppressed
			}
		}

		# If the diff cannot be shown due to a deleted revision, then output
		# the diff header and links to unhide (if available)...
		if ( $deleted && ( !$this->unhide || !$allowed ) ) {
			$this->showDiffStyle();
			$multi = $this->getMultiNotice();
			$out->addHTML( $this->addHeader( '', $oldHeader, $newHeader, $multi ) );
			if ( !$allowed ) {
				$msg = $suppressed ? 'rev-suppressed-no-diff' : 'rev-deleted-no-diff';
				# Give explanation for why revision is not visible
				$out->wrapWikiMsg( "<div id='mw-$msg' class='mw-warning plainlinks'>\n$1\n</div>\n",
					array( $msg ) );
			} else {
				# Give explanation and add a link to view the diff...
				$query = $this->getRequest()->appendQueryValue( 'unhide', '1', true );
				$link = $this->getTitle()->getFullURL( $query );
				$msg = $suppressed ? 'rev-suppressed-unhide-diff' : 'rev-deleted-unhide-diff';
				$out->wrapWikiMsg(
					"<div id='mw-$msg' class='mw-warning plainlinks'>\n$1\n</div>\n",
					array( $msg, $link )
				);
			}
		# Otherwise, output a regular diff...
		} else {
			# Add deletion notice if the user is viewing deleted content
			$notice = '';
			if ( $deleted ) {
				$msg = $suppressed ? 'rev-suppressed-diff-view' : 'rev-deleted-diff-view';
				$notice = "<div id='mw-$msg' class='mw-warning plainlinks'>\n" .
					$this->msg( $msg )->parse() .
					"</div>\n";
			}
			$this->showDiff( $oldHeader, $newHeader, $notice );
			if ( !$diffOnly ) {
				$this->renderNewRevision();
			}
		}
		wfProfileOut( __METHOD__ );
	}

	/**
	 * Get a link to mark the change as patrolled, or '' if there's either no
	 * revision to patrol or the user is not allowed to to it.
	 * Side effect: When the patrol link is build, this method will call
	 * OutputPage::preventClickjacking() and load mediawiki.page.patrol.ajax.
	 *
	 * @return String
	 */
	protected function markPatrolledLink() {
		global $wgUseRCPatrol, $wgEnableAPI, $wgEnableWriteAPI;
		$user = $this->getUser();

		if ( $this->markPatrolledLink === null ) {
			// Prepare a change patrol link, if applicable
			if (
				// Is patrolling enabled and the user allowed to?
				$wgUseRCPatrol && $this->newPage->quickUserCan( 'patrol', $user ) &&
				// Only do this if the revision isn't more than 6 hours older
				// than the Max RC age (6h because the RC might not be cleaned out regularly)
				RecentChange::isInRCLifespan( $this->newRev->getTimestamp(), 21600 )
			) {
				// Look for an unpatrolled change corresponding to this diff

				$db = wfGetDB( DB_SLAVE );
				$change = RecentChange::newFromConds(
					array(
						'rc_timestamp' => $db->timestamp( $this->newRev->getTimestamp() ),
						'rc_this_oldid' => $this->newId,
						'rc_patrolled' => 0
					),
					__METHOD__,
					array( 'USE INDEX' => 'rc_timestamp' )
				);

				if ( $change && $change->getPerformer()->getName() !== $user->getName() ) {
					$rcid = $change->getAttribute( 'rc_id' );
				} else {
					// None found or the page has been created by the current user.
					// If the user could patrol this it already would be patrolled
					$rcid = 0;
				}
				// Build the link
				if ( $rcid ) {
					$this->getOutput()->preventClickjacking();
					if ( $wgEnableAPI && $wgEnableWriteAPI
						&& $user->isAllowed( 'writeapi' )
					) {
						$this->getOutput()->addModules( 'mediawiki.page.patrol.ajax' );
					}

					$token = $user->getEditToken( $rcid );
					$this->markPatrolledLink = ' <span class="patrollink">[' . Linker::linkKnown(
						$this->newPage,
						$this->msg( 'markaspatrolleddiff' )->escaped(),
						array(),
						array(
							'action' => 'markpatrolled',
							'rcid' => $rcid,
							'token' => $token,
						)
					) . ']</span>';
				} else {
					$this->markPatrolledLink = '';
				}
			} else {
				$this->markPatrolledLink = '';
			}
		}

		return $this->markPatrolledLink;
	}

	/**
	 * @param $rev Revision
	 * @return String
	 */
	protected function revisionDeleteLink( $rev ) {
		$link = Linker::getRevDeleteLink( $this->getUser(), $rev, $rev->getTitle() );
		if ( $link !== '' ) {
			$link = '&#160;&#160;&#160;' . $link . ' ';
		}
		return $link;
	}

	/**
	 * Show the new revision of the page.
	 */
	function renderNewRevision() {
		wfProfileIn( __METHOD__ );
		$out = $this->getOutput();
		$revHeader = $this->getRevisionHeader( $this->newRev );
		# Add "current version as of X" title
		$out->addHTML( "<hr class='diff-hr' />
		<h2 class='diff-currentversion-title'>{$revHeader}</h2>\n" );
		# Page content may be handled by a hooked call instead...
		# @codingStandardsIgnoreStart Ignoring long lines.
		if ( wfRunHooks( 'ArticleContentOnDiff', array( $this, $out ) ) ) {
			$this->loadNewText();
			$out->setRevisionId( $this->newId );
			$out->setRevisionTimestamp( $this->newRev->getTimestamp() );
			$out->setArticleFlag( true );

			// NOTE: only needed for B/C: custom rendering of JS/CSS via hook
			if ( $this->newPage->isCssJsSubpage() || $this->newPage->isCssOrJsPage() ) {
				// Stolen from Article::view --AG 2007-10-11
				// Give hooks a chance to customise the output
				// @todo standardize this crap into one function
				if ( ContentHandler::runLegacyHooks( 'ShowRawCssJs', array( $this->newContent, $this->newPage, $out ) ) ) {
					// NOTE: deprecated hook, B/C only
					// use the content object's own rendering
					$cnt = $this->newRev->getContent();
					$po = $cnt ? $cnt->getParserOutput( $this->newRev->getTitle(), $this->newRev->getId() ) : null;
					$txt = $po ? $po->getText() : '';
					$out->addHTML( $txt );
				}
			} elseif ( !wfRunHooks( 'ArticleContentViewCustom', array( $this->newContent, $this->newPage, $out ) ) ) {
				// Handled by extension
			} elseif ( !ContentHandler::runLegacyHooks( 'ArticleViewCustom', array( $this->newContent, $this->newPage, $out ) ) ) {
				// NOTE: deprecated hook, B/C only
				// Handled by extension
			} else {
				// Normal page
				if ( $this->getTitle()->equals( $this->newPage ) ) {
					// If the Title stored in the context is the same as the one
					// of the new revision, we can use its associated WikiPage
					// object.
					$wikiPage = $this->getWikiPage();
				} else {
					// Otherwise we need to create our own WikiPage object
					$wikiPage = WikiPage::factory( $this->newPage );
				}

				$parserOutput = $this->getParserOutput( $wikiPage, $this->newRev );

				# Also try to load it as a redirect
				$rt = $this->newContent ? $this->newContent->getRedirectTarget() : null;

				if ( $rt ) {
					$article = Article::newFromTitle( $this->newPage, $this->getContext() );
					$out->addHTML( $article->viewRedirect( $rt ) );

					# WikiPage::getParserOutput() should not return false, but just in case
					if ( $parserOutput ) {
						# Show categories etc.
						$out->addParserOutputNoText( $parserOutput );
					}
				} elseif ( $parserOutput ) {
					$out->addParserOutput( $parserOutput );
				}
			}
		}
		# @codingStandardsIgnoreEnd

		# Add redundant patrol link on bottom...
		$out->addHTML( $this->markPatrolledLink() );

		wfProfileOut( __METHOD__ );
	}

	protected function getParserOutput( WikiPage $page, Revision $rev ) {
		$parserOptions = $page->makeParserOptions( $this->getContext() );

		if ( !$rev->isCurrent() || !$rev->getTitle()->quickUserCan( "edit" ) ) {
			$parserOptions->setEditSection( false );
		}

		$parserOutput = $page->getParserOutput( $parserOptions, $rev->getId() );
		return $parserOutput;
	}

	/**
	 * Get the diff text, send it to the OutputPage object
	 * Returns false if the diff could not be generated, otherwise returns true
	 *
	 * @param string|bool $otitle Header for old text or false
	 * @param string|bool $ntitle Header for new text or false
	 * @param string $notice HTML between diff header and body
	 *
	 * @return bool
	 */
	function showDiff( $otitle, $ntitle, $notice = '' ) {
		$diff = $this->getDiff( $otitle, $ntitle, $notice );
		if ( $diff === false ) {
			$this->showMissingRevision();
			return false;
		} else {
			$this->showDiffStyle();
			$this->getOutput()->addHTML( $diff );
			return true;
		}
	}

	/**
	 * Add style sheets and supporting JS for diff display.
	 */
	function showDiffStyle() {
		$this->getOutput()->addModuleStyles( 'mediawiki.action.history.diff' );
	}

	/**
	 * Get complete diff table, including header
	 *
	 * @param string|bool $otitle Header for old text or false
	 * @param string|bool $ntitle Header for new text or false
	 * @param string $notice HTML between diff header and body
	 * @return mixed
	 */
	function getDiff( $otitle, $ntitle, $notice = '' ) {
		$body = $this->getDiffBody();
		if ( $body === false ) {
			return false;
		}

		$multi = $this->getMultiNotice();
		// Display a message when the diff is empty
		if ( $body === '' ) {
			$notice .= '<div class="mw-diff-empty">' .
				$this->msg( 'diff-empty' )->parse() .
				"</div>\n";
		}

		return $this->addHeader( $body, $otitle, $ntitle, $multi, $notice );
	}

	/**
	 * Get the diff table body, without header
	 *
	 * @return mixed (string/false)
	 */
	public function getDiffBody() {
		global $wgMemc;
		wfProfileIn( __METHOD__ );
		$this->cacheHit = true;
		// Check if the diff should be hidden from this user
		if ( !$this->loadRevisionData() ) {
			wfProfileOut( __METHOD__ );
			return false;
		} elseif ( $this->oldRev &&
			!$this->oldRev->userCan( Revision::DELETED_TEXT, $this->getUser() )
		) {
			wfProfileOut( __METHOD__ );
			return false;
		} elseif ( $this->newRev &&
			!$this->newRev->userCan( Revision::DELETED_TEXT, $this->getUser() )
		) {
			wfProfileOut( __METHOD__ );
			return false;
		}
		// Short-circuit
		if ( $this->oldRev === false || ( $this->oldRev && $this->newRev
			&& $this->oldRev->getID() == $this->newRev->getID() ) )
		{
			wfProfileOut( __METHOD__ );
			return '';
		}
		// Cacheable?
		$key = false;
		if ( $this->oldId && $this->newId ) {
			$key = $this->getDiffBodyCacheKey();

			// Try cache
			if ( !$this->refreshCache ) {
				$difftext = $wgMemc->get( $key );
				if ( $difftext ) {
					wfIncrStats( 'diff_cache_hit' );
					$difftext = $this->localiseLineNumbers( $difftext );
					$difftext .= "\n<!-- diff cache key $key -->\n";
					wfProfileOut( __METHOD__ );
					return $difftext;
				}
			} // don't try to load but save the result
		}
		$this->cacheHit = false;

		// Loadtext is permission safe, this just clears out the diff
		if ( !$this->loadText() ) {
			wfProfileOut( __METHOD__ );
			return false;
		}

		$difftext = $this->generateContentDiffBody( $this->oldContent, $this->newContent );

		// Save to cache for 7 days
		if ( !wfRunHooks( 'AbortDiffCache', array( &$this ) ) ) {
			wfIncrStats( 'diff_uncacheable' );
		} elseif ( $key !== false && $difftext !== false ) {
			wfIncrStats( 'diff_cache_miss' );
			$wgMemc->set( $key, $difftext, 7 * 86400 );
		} else {
			wfIncrStats( 'diff_uncacheable' );
		}
		// Replace line numbers with the text in the user's language
		if ( $difftext !== false ) {
			$difftext = $this->localiseLineNumbers( $difftext );
		}
		wfProfileOut( __METHOD__ );
		return $difftext;
	}

	/**
	 * Returns the cache key for diff body text or content.
	 *
	 * @return string
	 * @since 1.23
	 * @throws MWException
	 */
	protected function getDiffBodyCacheKey() {
		if ( !$this->oldId || !$this->newId ) {
			throw new MWException( 'oldId and newId must be set to get diff cache key.' );
		}

		return wfMemcKey( 'diff', 'version', self::CACHE_VERSION,
			'oldid', $this->oldId, 'newid', $this->newId );
	}

	/**
	 * Generate a diff, no caching.
	 *
	 * This implementation uses generateTextDiffBody() to generate a diff based on the default
	 * serialization of the given Content objects. This will fail if $old or $new are not
	 * instances of TextContent.
	 *
	 * Subclasses may override this to provide a different rendering for the diff,
	 * perhaps taking advantage of the content's native form. This is required for all content
	 * models that are not text based.
	 *
	 * @param $old Content: old content
	 * @param $new Content: new content
	 *
	 * @return bool|string
	 * @since 1.21
	 * @throws MWException if $old or $new are not instances of TextContent.
	 */
	function generateContentDiffBody( Content $old, Content $new ) {
		if ( !( $old instanceof TextContent ) ) {
			throw new MWException( "Diff not implemented for " . get_class( $old ) . "; "
					. "override generateContentDiffBody to fix this." );
		}

		if ( !( $new instanceof TextContent ) ) {
			throw new MWException( "Diff not implemented for " . get_class( $new ) . "; "
				. "override generateContentDiffBody to fix this." );
		}

		$otext = $old->serialize();
		$ntext = $new->serialize();

		return $this->generateTextDiffBody( $otext, $ntext );
	}

	/**
	 * Generate a diff, no caching
	 *
	 * @param string $otext old text, must be already segmented
	 * @param string $ntext new text, must be already segmented
	 * @return bool|string
	 * @deprecated since 1.21, use generateContentDiffBody() instead!
	 */
	function generateDiffBody( $otext, $ntext ) {
		ContentHandler::deprecated( __METHOD__, "1.21" );

		return $this->generateTextDiffBody( $otext, $ntext );
	}

	/**
	 * Generate a diff, no caching
	 *
	 * @todo move this to TextDifferenceEngine, make DifferenceEngine abstract. At some point.
	 *
	 * @param string $otext old text, must be already segmented
	 * @param string $ntext new text, must be already segmented
	 * @return bool|string
	 */
	function generateTextDiffBody( $otext, $ntext ) {
		global $wgExternalDiffEngine, $wgContLang;

		wfProfileIn( __METHOD__ );

		$otext = str_replace( "\r\n", "\n", $otext );
		$ntext = str_replace( "\r\n", "\n", $ntext );

		if ( $wgExternalDiffEngine == 'wikidiff' && function_exists( 'wikidiff_do_diff' ) ) {
			# For historical reasons, external diff engine expects
			# input text to be HTML-escaped already
			$otext = htmlspecialchars ( $wgContLang->segmentForDiff( $otext ) );
			$ntext = htmlspecialchars ( $wgContLang->segmentForDiff( $ntext ) );
			wfProfileOut( __METHOD__ );
			return $wgContLang->unsegmentForDiff( wikidiff_do_diff( $otext, $ntext, 2 ) ) .
			$this->debug( 'wikidiff1' );
		}

		if ( $wgExternalDiffEngine == 'wikidiff2' && function_exists( 'wikidiff2_do_diff' ) ) {
			# Better external diff engine, the 2 may some day be dropped
			# This one does the escaping and segmenting itself
			wfProfileIn( 'wikidiff2_do_diff' );
			$text = wikidiff2_do_diff( $otext, $ntext, 2 );
			$text .= $this->debug( 'wikidiff2' );
			wfProfileOut( 'wikidiff2_do_diff' );
			wfProfileOut( __METHOD__ );
			return $text;
		}
		if ( $wgExternalDiffEngine != 'wikidiff3' && $wgExternalDiffEngine !== false ) {
			# Diff via the shell
			$tmpDir = wfTempDir();
			$tempName1 = tempnam( $tmpDir, 'diff_' );
			$tempName2 = tempnam( $tmpDir, 'diff_' );

			$tempFile1 = fopen( $tempName1, "w" );
			if ( !$tempFile1 ) {
				wfProfileOut( __METHOD__ );
				return false;
			}
			$tempFile2 = fopen( $tempName2, "w" );
			if ( !$tempFile2 ) {
				wfProfileOut( __METHOD__ );
				return false;
			}
			fwrite( $tempFile1, $otext );
			fwrite( $tempFile2, $ntext );
			fclose( $tempFile1 );
			fclose( $tempFile2 );
			$cmd = wfEscapeShellArg( $wgExternalDiffEngine, $tempName1, $tempName2 );
			wfProfileIn( __METHOD__ . "-shellexec" );
			$difftext = wfShellExec( $cmd );
			$difftext .= $this->debug( "external $wgExternalDiffEngine" );
			wfProfileOut( __METHOD__ . "-shellexec" );
			unlink( $tempName1 );
			unlink( $tempName2 );
			wfProfileOut( __METHOD__ );
			return $difftext;
		}

		# Native PHP diff
		$ota = explode( "\n", $wgContLang->segmentForDiff( $otext ) );
		$nta = explode( "\n", $wgContLang->segmentForDiff( $ntext ) );
		$diffs = new Diff( $ota, $nta );
		$formatter = new TableDiffFormatter();
		$difftext = $wgContLang->unsegmentForDiff( $formatter->format( $diffs ) ) .
		wfProfileOut( __METHOD__ );
		return $difftext;
	}

	/**
	 * Generate a debug comment indicating diff generating time,
	 * server node, and generator backend.
	 *
	 * @param String $generator: What diff engine was used
	 *
	 * @return string
	 */
	protected function debug( $generator = "internal" ) {
		global $wgShowHostnames;
		if ( !$this->enableDebugComment ) {
			return '';
		}
		$data = array( $generator );
		if ( $wgShowHostnames ) {
			$data[] = wfHostname();
		}
		$data[] = wfTimestamp( TS_DB );
		return "<!-- diff generator: "
			. implode( " ",
				array_map(
					"htmlspecialchars",
				$data )
			)
			. " -->\n";
	}

	/**
	 * Replace line numbers with the text in the user's language
	 *
	 * @param String $text
	 *
	 * @return mixed
	 */
	function localiseLineNumbers( $text ) {
		return preg_replace_callback( '/<!--LINE (\d+)-->/',
		array( &$this, 'localiseLineNumbersCb' ), $text );
	}

	function localiseLineNumbersCb( $matches ) {
		if ( $matches[1] === '1' && $this->reducedLineNumbers ) {
			return '';
		}
		return $this->msg( 'lineno' )->numParams( $matches[1] )->escaped();
	}

	/**
	 * If there are revisions between the ones being compared, return a note saying so.
	 * @return string
	 */
	function getMultiNotice() {
		if ( !is_object( $this->oldRev ) || !is_object( $this->newRev ) ) {
			return '';
		} elseif ( !$this->oldPage->equals( $this->newPage ) ) {
			// Comparing two different pages? Count would be meaningless.
			return '';
		}

		if ( $this->oldRev->getTimestamp() > $this->newRev->getTimestamp() ) {
			$oldRev = $this->newRev; // flip
			$newRev = $this->oldRev; // flip
		} else { // normal case
			$oldRev = $this->oldRev;
			$newRev = $this->newRev;
		}

		$nEdits = $this->newPage->countRevisionsBetween( $oldRev, $newRev );
		if ( $nEdits > 0 ) {
			$limit = 100; // use diff-multi-manyusers if too many users
			$numUsers = $this->newPage->countAuthorsBetween( $oldRev, $newRev, $limit );
			return self::intermediateEditsMsg( $nEdits, $numUsers, $limit );
		}
		return ''; // nothing
	}

	/**
	 * Get a notice about how many intermediate edits and users there are
	 * @param $numEdits int
	 * @param $numUsers int
	 * @param $limit int
	 * @return string
	 */
	public static function intermediateEditsMsg( $numEdits, $numUsers, $limit ) {
		if ( $numUsers > $limit ) {
			$msg = 'diff-multi-manyusers';
			$numUsers = $limit;
		} else {
			$msg = 'diff-multi';
		}
		return wfMessage( $msg )->numParams( $numEdits, $numUsers )->parse();
	}

	/**
	 * Get a header for a specified revision.
	 *
	 * @param $rev Revision
	 * @param string $complete 'complete' to get the header wrapped depending
	 *        the visibility of the revision and a link to edit the page.
	 * @return String HTML fragment
	 */
	protected function getRevisionHeader( Revision $rev, $complete = '' ) {
		$lang = $this->getLanguage();
		$user = $this->getUser();
		$revtimestamp = $rev->getTimestamp();
		$timestamp = $lang->userTimeAndDate( $revtimestamp, $user );
		$dateofrev = $lang->userDate( $revtimestamp, $user );
		$timeofrev = $lang->userTime( $revtimestamp, $user );

		$header = $this->msg(
			$rev->isCurrent() ? 'currentrev-asof' : 'revisionasof',
			$timestamp,
			$dateofrev,
			$timeofrev
		)->escaped();

		if ( $complete !== 'complete' ) {
			return $header;
		}

		$title = $rev->getTitle();

		$header = Linker::linkKnown( $title, $header, array(),
			array( 'oldid' => $rev->getID() ) );

		if ( $rev->userCan( Revision::DELETED_TEXT, $user ) ) {
			$editQuery = array( 'action' => 'edit' );
			if ( !$rev->isCurrent() ) {
				$editQuery['oldid'] = $rev->getID();
			}

			$key = $title->quickUserCan( 'edit', $user ) ? 'editold' : 'viewsourceold';
			$msg = $this->msg( $key )->escaped();
			$header .= ' ' . $this->msg( 'parentheses' )->rawParams(
				Linker::linkKnown( $title, $msg, array(), $editQuery ) )->plain();
			if ( $rev->isDeleted( Revision::DELETED_TEXT ) ) {
				$header = Html::rawElement(
					'span',
					array( 'class' => 'history-deleted' ),
					$header
				);
			}
		} else {
			$header = Html::rawElement( 'span', array( 'class' => 'history-deleted' ), $header );
		}

		return $header;
	}

	/**
	 * Add the header to a diff body
	 *
	 * @param String $diff: Diff body
	 * @param String $otitle: Old revision header
	 * @param String $ntitle: New revision header
	 * @param String $multi: Notice telling user that there are intermediate revisions between the ones being compared
	 * @param String $notice: Other notices, e.g. that user is viewing deleted content
	 *
	 * @return string
	 */
	function addHeader( $diff, $otitle, $ntitle, $multi = '', $notice = '' ) {
		// shared.css sets diff in interface language/dir, but the actual content
		// is often in a different language, mostly the page content language/dir
		$tableClass = 'diff diff-contentalign-' . htmlspecialchars( $this->getDiffLang()->alignStart() );
		$header = "<table class='$tableClass'>";

		if ( !$diff && !$otitle ) {
			$header .= "
			<tr style='vertical-align: top;'>
			<td class='diff-ntitle'>{$ntitle}</td>
			</tr>";
			$multiColspan = 1;
		} else {
			if ( $diff ) { // Safari/Chrome show broken output if cols not used
				$header .= "
				<col class='diff-marker' />
				<col class='diff-content' />
				<col class='diff-marker' />
				<col class='diff-content' />";
				$colspan = 2;
				$multiColspan = 4;
			} else {
				$colspan = 1;
				$multiColspan = 2;
			}
			if ( $otitle || $ntitle ) {
				$header .= "
				<tr style='vertical-align: top;'>
				<td colspan='$colspan' class='diff-otitle'>{$otitle}</td>
				<td colspan='$colspan' class='diff-ntitle'>{$ntitle}</td>
				</tr>";
			}
		}

		if ( $multi != '' ) {
			$header .= "<tr><td colspan='{$multiColspan}' style='text-align: center;' " .
				"class='diff-multi'>{$multi}</td></tr>";
		}
		if ( $notice != '' ) {
			$header .= "<tr><td colspan='{$multiColspan}' style='text-align: center;'>{$notice}</td></tr>";
		}

		return $header . $diff . "</table>";
	}

	/**
	 * Use specified text instead of loading from the database
	 * @deprecated since 1.21, use setContent() instead.
	 */
	function setText( $oldText, $newText ) {
		ContentHandler::deprecated( __METHOD__, "1.21" );

		$oldContent = ContentHandler::makeContent( $oldText, $this->getTitle() );
		$newContent = ContentHandler::makeContent( $newText, $this->getTitle() );

		$this->setContent( $oldContent, $newContent );
	}

	/**
	 * Use specified text instead of loading from the database
	 * @since 1.21
	 */
	function setContent( Content $oldContent, Content $newContent ) {
		$this->oldContent = $oldContent;
		$this->newContent = $newContent;

		$this->textLoaded = 2;
		$this->revisionsLoaded = true;
	}

	/**
	 * Set the language in which the diff text is written
	 * (Defaults to page content language).
	 * @since 1.19
	 */
	function setTextLanguage( $lang ) {
		$this->diffLang = wfGetLangObj( $lang );
	}

	/**
	 * Maps a revision pair definition as accepted by DifferenceEngine constructor
	 * to a pair of actual integers representing revision ids.
	 *
	 * @param int $old Revision id, e.g. from URL parameter 'oldid'
	 * @param int|string $new Revision id or strings 'next' or 'prev', e.g. from URL parameter 'diff'
	 * @return array Array of two revision ids, older first, later second.
	 *     Zero signifies invalid argument passed.
	 *     false signifies that there is no previous/next revision ($old is the oldest/newest one).
	 */
	public function mapDiffPrevNext( $old, $new ) {
		if ( $new === 'prev' ) {
			// Show diff between revision $old and the previous one. Get previous one from DB.
			$newid = intval( $old );
			$oldid = $this->getTitle()->getPreviousRevisionID( $newid );
		} elseif ( $new === 'next' ) {
			// Show diff between revision $old and the next one. Get next one from DB.
			$oldid = intval( $old );
			$newid = $this->getTitle()->getNextRevisionID( $oldid );
		} else {
			$oldid = intval( $old );
			$newid = intval( $new );
		}

		return array( $oldid, $newid );
	}

	/**
	 * Load revision IDs
	 */
	private function loadRevisionIds() {
		if ( $this->revisionsIdsLoaded ) {
			return;
		}

		$this->revisionsIdsLoaded = true;

		$old = $this->oldId;
		$new = $this->newId;

		list( $this->oldId, $this->newId ) = self::mapDiffPrevNext( $old, $new );
		if ( $new === 'next' && $this->newId === false ) {
			# if no result, NewId points to the newest old revision. The only newer
			# revision is cur, which is "0".
			$this->newId = 0;
		}

		wfRunHooks( 'NewDifferenceEngine', array( $this->getTitle(), &$this->oldId, &$this->newId, $old, $new ) );
	}

	/**
	 * Load revision metadata for the specified articles. If newid is 0, then compare
	 * the old article in oldid to the current article; if oldid is 0, then
	 * compare the current article to the immediately previous one (ignoring the
	 * value of newid).
	 *
	 * If oldid is false, leave the corresponding revision object set
	 * to false. This is impossible via ordinary user input, and is provided for
	 * API convenience.
	 *
	 * @return bool
	 */
	function loadRevisionData() {
		if ( $this->revisionsLoaded ) {
			return true;
		}

		// Whether it succeeds or fails, we don't want to try again
		$this->revisionsLoaded = true;

		$this->loadRevisionIds();

		// Load the new revision object
		if ( $this->newId ) {
			$this->newRev = Revision::newFromId( $this->newId );
		} else {
			$this->newRev = Revision::newFromTitle(
				$this->getTitle(),
				false,
				Revision::READ_NORMAL
			);
		}

		if ( !$this->newRev instanceof Revision ) {
			return false;
		}

		// Update the new revision ID in case it was 0 (makes life easier doing UI stuff)
		$this->newId = $this->newRev->getId();
		$this->newPage = $this->newRev->getTitle();

		// Load the old revision object
		$this->oldRev = false;
		if ( $this->oldId ) {
			$this->oldRev = Revision::newFromId( $this->oldId );
		} elseif ( $this->oldId === 0 ) {
			$rev = $this->newRev->getPrevious();
			if ( $rev ) {
				$this->oldId = $rev->getId();
				$this->oldRev = $rev;
			} else {
				// No previous revision; mark to show as first-version only.
				$this->oldId = false;
				$this->oldRev = false;
			}
		} /* elseif ( $this->oldId === false ) leave oldRev false; */

		if ( is_null( $this->oldRev ) ) {
			return false;
		}

		if ( $this->oldRev ) {
			$this->oldPage = $this->oldRev->getTitle();
		}

		// Load tags information for both revisions
		$dbr = wfGetDB( DB_SLAVE );
		if ( $this->oldId !== false ) {
			$this->oldTags = $dbr->selectField(
				'tag_summary',
				'ts_tags',
				array( 'ts_rev_id' => $this->oldId ),
				__METHOD__
			);
		} else {
			$this->oldTags = false;
		}
		$this->newTags = $dbr->selectField(
			'tag_summary',
			'ts_tags',
			array( 'ts_rev_id' => $this->newId ),
			__METHOD__
		);

		return true;
	}

	/**
	 * Load the text of the revisions, as well as revision data.
	 *
	 * @return bool
	 */
	function loadText() {
		if ( $this->textLoaded == 2 ) {
			return true;
		}

		// Whether it succeeds or fails, we don't want to try again
		$this->textLoaded = 2;

		if ( !$this->loadRevisionData() ) {
			return false;
		}

		if ( $this->oldRev ) {
			$this->oldContent = $this->oldRev->getContent( Revision::FOR_THIS_USER, $this->getUser() );
			if ( $this->oldContent === null ) {
				return false;
			}
		}

		if ( $this->newRev ) {
			$this->newContent = $this->newRev->getContent( Revision::FOR_THIS_USER, $this->getUser() );
			if ( $this->newContent === null ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Load the text of the new revision, not the old one
	 *
	 * @return bool
	 */
	function loadNewText() {
		if ( $this->textLoaded >= 1 ) {
			return true;
		}

		$this->textLoaded = 1;

		if ( !$this->loadRevisionData() ) {
			return false;
		}

		$this->newContent = $this->newRev->getContent( Revision::FOR_THIS_USER, $this->getUser() );

		return true;
	}
}
