<?php

final class DiffusionBrowseController extends DiffusionController {

  private $lintCommit;
  private $lintMessages;
  private $coverage;
  private $corpusButtons = array();

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $response = $this->loadDiffusionContext();
    if ($response) {
      return $response;
    }

    $drequest = $this->getDiffusionRequest();

    // Figure out if we're browsing a directory, a file, or a search result
    // list.

    $grep = $request->getStr('grep');
    if (strlen($grep)) {
      return $this->browseSearch();
    }

    $pager = id(new PHUIPagerView())
      ->readFromRequest($request);

    $results = DiffusionBrowseResultSet::newFromConduit(
      $this->callConduitWithDiffusionRequest(
        'diffusion.browsequery',
        array(
          'path' => $drequest->getPath(),
          'commit' => $drequest->getStableCommit(),
          'offset' => $pager->getOffset(),
          'limit' => $pager->getPageSize() + 1,
        )));

    $reason = $results->getReasonForEmptyResultSet();
    $is_file = ($reason == DiffusionBrowseResultSet::REASON_IS_FILE);

    if ($is_file) {
      return $this->browseFile();
    } else {
      $paths = $results->getPaths();
      $paths = $pager->sliceResults($paths);
      $results->setPaths($paths);

      return $this->browseDirectory($results, $pager);
    }
  }

  private function browseSearch() {
    $drequest = $this->getDiffusionRequest();
    $header = $this->buildHeaderView($drequest);
    $path = nonempty(basename($drequest->getPath()), '/');

    $search_results = $this->renderSearchResults();
    $search_form = $this->renderSearchForm($path);

    $search_form = phutil_tag(
      'div',
      array(
        'class' => 'diffusion-mobile-search-form',
      ),
      $search_form);

    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'browse',
      ));
    $crumbs->setBorder(true);

    $tabs = $this->buildTabsView('code');

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setTabs($tabs)
      ->setFooter(
        array(
          $search_form,
          $search_results,
        ));

    return $this->newPage()
      ->setTitle(
        array(
          nonempty(basename($drequest->getPath()), '/'),
          $drequest->getRepository()->getDisplayName(),
        ))
      ->setCrumbs($crumbs)
      ->appendChild($view);
  }

  private function browseFile() {
    $viewer = $this->getViewer();
    $request = $this->getRequest();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $before = $request->getStr('before');
    if ($before) {
      return $this->buildBeforeResponse($before);
    }

    $path = $drequest->getPath();

    $blame_key = PhabricatorDiffusionBlameSetting::SETTINGKEY;
    $show_blame = $request->getBool(
      'blame',
      $viewer->getUserSetting($blame_key));

    $view = $request->getStr('view');
    if ($request->isFormPost() && $view != 'raw' && $viewer->isLoggedIn()) {
      $preferences = PhabricatorUserPreferences::loadUserPreferences($viewer);

      $editor = id(new PhabricatorUserPreferencesEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true);

      $xactions = array();
      $xactions[] = $preferences->newTransaction($blame_key, $show_blame);
      $editor->applyTransactions($preferences, $xactions);

      $uri = $request->getRequestURI()
        ->alter('blame', null);

      return id(new AphrontRedirectResponse())->setURI($uri);
    }

    // We need the blame information if blame is on and this is an Ajax request.
    // If blame is on and this is a colorized request, we don't show blame at
    // first (we ajax it in afterward) so we don't need to query for it.
    $needs_blame = ($show_blame && $request->isAjax());

    $params = array(
      'commit' => $drequest->getCommit(),
      'path' => $drequest->getPath(),
    );

    $byte_limit = null;
    if ($view !== 'raw') {
      $byte_limit = PhabricatorFileStorageEngine::getChunkThreshold();
      $time_limit = 10;

      $params += array(
        'timeout' => $time_limit,
        'byteLimit' => $byte_limit,
      );
    }

    $response = $this->callConduitWithDiffusionRequest(
      'diffusion.filecontentquery',
      $params);

    $hit_byte_limit = $response['tooHuge'];
    $hit_time_limit = $response['tooSlow'];

    $file_phid = $response['filePHID'];
    $show_editor = false;
    if ($hit_byte_limit) {
      $corpus = $this->buildErrorCorpus(
        pht(
          'This file is larger than %s byte(s), and too large to display '.
          'in the web UI.',
          phutil_format_bytes($byte_limit)));
    } else if ($hit_time_limit) {
      $corpus = $this->buildErrorCorpus(
        pht(
          'This file took too long to load from the repository (more than '.
          '%s second(s)).',
          new PhutilNumber($time_limit)));
    } else {
      $file = id(new PhabricatorFileQuery())
        ->setViewer($viewer)
        ->withPHIDs(array($file_phid))
        ->executeOne();
      if (!$file) {
        throw new Exception(pht('Failed to load content file!'));
      }

      if ($view === 'raw') {
        return $file->getRedirectResponse();
      }

      $data = $file->loadFileData();

      $lfs_ref = $this->getGitLFSRef($repository, $data);
      if ($lfs_ref) {
        if ($view == 'git-lfs') {
          $file = $this->loadGitLFSFile($lfs_ref);

          // Rename the file locally so we generate a better vanity URI for
          // it. In storage, it just has a name like "lfs-13f9a94c0923...",
          // since we don't get any hints about possible human-readable names
          // at upload time.
          $basename = basename($drequest->getPath());
          $file->makeEphemeral();
          $file->setName($basename);

          return $file->getRedirectResponse();
        } else {
          $corpus = $this->buildGitLFSCorpus($lfs_ref);
        }
      } else if (ArcanistDiffUtils::isHeuristicBinaryFile($data)) {
        $file_uri = $file->getBestURI();

        if ($file->isViewableImage()) {
          $corpus = $this->buildImageCorpus($file_uri);
        } else {
          $corpus = $this->buildBinaryCorpus($file_uri, $data);
        }
      } else {
        $this->loadLintMessages();
        $this->coverage = $drequest->loadCoverage();
        $show_editor = true;

        // Build the content of the file.
        $corpus = $this->buildCorpus(
          $show_blame,
          $data,
          $needs_blame,
          $drequest,
          $path,
          $data);
      }
    }

    if ($request->isAjax()) {
      return id(new AphrontAjaxResponse())->setContent($corpus);
    }

    require_celerity_resource('diffusion-source-css');

    // Render the page.
    $bar = $this->buildButtonBar($drequest, $show_blame, $show_editor);
    $header = $this->buildHeaderView($drequest);
    $header->setHeaderIcon('fa-file-code-o');

    $follow  = $request->getStr('follow');
    $follow_notice = null;
    if ($follow) {
      $follow_notice = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
        ->setTitle(pht('Unable to Continue'));
      switch ($follow) {
        case 'first':
          $follow_notice->appendChild(
            pht(
              'Unable to continue tracing the history of this file because '.
              'this commit is the first commit in the repository.'));
          break;
        case 'created':
          $follow_notice->appendChild(
            pht(
              'Unable to continue tracing the history of this file because '.
              'this commit created the file.'));
          break;
      }
    }

    $renamed = $request->getStr('renamed');
    $renamed_notice = null;
    if ($renamed) {
      $renamed_notice = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
        ->setTitle(pht('File Renamed'))
        ->appendChild(
          pht(
            'File history passes through a rename from "%s" to "%s".',
            $drequest->getPath(),
            $renamed));
    }

    $open_revisions = $this->buildOpenRevisions();
    $owners_list = $this->buildOwnersList($drequest);

    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'browse',
      ));
    $crumbs->setBorder(true);

    $basename = basename($this->getDiffusionRequest()->getPath());
    $tabs = $this->buildTabsView('code');
    $bar->setRight($this->corpusButtons);

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setTabs($tabs)
      ->setFooter(array(
        $bar,
        $follow_notice,
        $renamed_notice,
        $corpus,
        $open_revisions,
        $owners_list,
      ));

    $title = array($basename, $repository->getDisplayName());

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->appendChild(
        array(
          $view,
      ));

  }

  public function browseDirectory(
    DiffusionBrowseResultSet $results,
    PHUIPagerView $pager) {

    $request = $this->getRequest();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $reason = $results->getReasonForEmptyResultSet();

    $this->buildActionButtons($drequest, true);
    $details = $this->buildPropertyView($drequest);

    $header = $this->buildHeaderView($drequest);
    $header->setHeaderIcon('fa-folder-open');

    $empty_result = null;
    $browse_panel = null;
    $branch_panel = null;
    if (!$results->isValidResults()) {
      $empty_result = new DiffusionEmptyResultView();
      $empty_result->setDiffusionRequest($drequest);
      $empty_result->setDiffusionBrowseResultSet($results);
      $empty_result->setView($request->getStr('view'));
    } else {
      $phids = array();
      foreach ($results->getPaths() as $result) {
        $data = $result->getLastCommitData();
        if ($data) {
          if ($data->getCommitDetail('authorPHID')) {
            $phids[$data->getCommitDetail('authorPHID')] = true;
          }
        }
      }

      $phids = array_keys($phids);
      $handles = $this->loadViewerHandles($phids);

      $browse_table = id(new DiffusionBrowseTableView())
        ->setDiffusionRequest($drequest)
        ->setHandles($handles)
        ->setPaths($results->getPaths())
        ->setUser($request->getUser());

      $title = nonempty(basename($drequest->getPath()), '/');
      $icon = 'fa-folder-open';
      $browse_header = $this->buildPanelHeaderView($title, $icon);

      $browse_panel = id(new PHUIObjectBoxView())
        ->setHeader($browse_header)
        ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
        ->setTable($browse_table)
        ->addClass('diffusion-mobile-view')
        ->setPager($pager);

      $path = $drequest->getPath();
      $is_branch = (!strlen($path) && $repository->supportsBranchComparison());
      if ($is_branch) {
        $branch_panel = $this->buildBranchTable();
      }
    }

    $open_revisions = $this->buildOpenRevisions();
    $readme = $this->renderDirectoryReadme($results);

    $crumbs = $this->buildCrumbs(
      array(
        'branch' => true,
        'path'   => true,
        'view'   => 'browse',
      ));

    $crumbs->setBorder(true);
    $tabs = $this->buildTabsView('code');
    $owners_list = $this->buildOwnersList($drequest);
    $bar = id(new PHUILeftRightView())
      ->setRight($this->corpusButtons)
      ->addClass('diffusion-action-bar');

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setTabs($tabs)
      ->setFooter(
        array(
          $bar,
          $branch_panel,
          $empty_result,
          $browse_panel,
          $open_revisions,
          $owners_list,
          $readme,
        ));

    if ($details) {
      $view->addPropertySection(pht('Details'), $details);
    }

    return $this->newPage()
      ->setTitle(array(
          nonempty(basename($drequest->getPath()), '/'),
          $repository->getDisplayName(),
        ))
      ->setCrumbs($crumbs)
      ->appendChild(
        array(
          $view,
        ));
  }

  private function renderSearchResults() {
    $request = $this->getRequest();

    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();
    $results = array();

    $pager = id(new PHUIPagerView())
      ->readFromRequest($request);

    $search_mode = null;
    switch ($repository->getVersionControlSystem()) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        $results = array();
        break;
      default:
        if (strlen($this->getRequest()->getStr('grep'))) {
          $search_mode = 'grep';
          $query_string = $request->getStr('grep');
          $results = $this->callConduitWithDiffusionRequest(
            'diffusion.searchquery',
            array(
              'grep' => $query_string,
              'commit' => $drequest->getStableCommit(),
              'path' => $drequest->getPath(),
              'limit' => $pager->getPageSize() + 1,
              'offset' => $pager->getOffset(),
            ));
        }
        break;
    }
    $results = $pager->sliceResults($results);

    $table = null;
    $header = null;
    if ($search_mode == 'grep') {
      $table = $this->renderGrepResults($results, $query_string);
      $title = pht(
        'File content matching "%s" under "%s"',
        $query_string,
        nonempty($drequest->getPath(), '/'));
      $header = id(new PHUIHeaderView())
        ->setHeader($title)
        ->addClass('diffusion-search-result-header');
    }

    return array($header, $table, $pager);

  }

  private function renderGrepResults(array $results, $pattern) {
    $drequest = $this->getDiffusionRequest();
    require_celerity_resource('phabricator-search-results-css');

    if (!$results) {
      return id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NODATA)
        ->appendChild(
          pht(
            'The pattern you searched for was not found in the content of any '.
            'files.'));
    }

    $grouped = array();
    foreach ($results as $file) {
      list($path, $line, $string) = $file;
      $grouped[$path][] = array($line, $string);
    }

    $view = array();
    foreach ($grouped as $path => $matches) {
      $view[] = id(new DiffusionPatternSearchView())
        ->setPath($path)
        ->setMatches($matches)
        ->setPattern($pattern)
        ->setDiffusionRequest($drequest)
        ->render();
    }

    return $view;
  }

  private function loadLintMessages() {
    $drequest = $this->getDiffusionRequest();
    $branch = $drequest->loadBranch();

    if (!$branch || !$branch->getLintCommit()) {
      return;
    }

    $this->lintCommit = $branch->getLintCommit();

    $conn = id(new PhabricatorRepository())->establishConnection('r');

    $where = '';
    if ($drequest->getLint()) {
      $where = qsprintf(
        $conn,
        'AND code = %s',
        $drequest->getLint());
    }

    $this->lintMessages = queryfx_all(
      $conn,
      'SELECT * FROM %T WHERE branchID = %d %Q AND path = %s',
      PhabricatorRepository::TABLE_LINTMESSAGE,
      $branch->getID(),
      $where,
      '/'.$drequest->getPath());
  }

  private function buildCorpus(
    $show_blame,
    $file_corpus,
    $needs_blame,
    DiffusionRequest $drequest,
    $path,
    $data) {

    $viewer = $this->getViewer();
    $blame_timeout = 15;
    $blame_failed = false;

    $highlight_limit = DifferentialChangesetParser::HIGHLIGHT_BYTE_LIMIT;
    $blame_limit = DifferentialChangesetParser::HIGHLIGHT_BYTE_LIMIT;
    $can_highlight = (strlen($file_corpus) <= $highlight_limit);
    $can_blame = (strlen($file_corpus) <= $blame_limit);

    if ($needs_blame && $can_blame) {
      $blame = $this->loadBlame($path, $drequest->getCommit(), $blame_timeout);
      list($blame_list, $blame_commits) = $blame;
      if ($blame_list === null) {
        $blame_failed = true;
        $blame_list = array();
      }
    } else {
      $blame_list = array();
      $blame_commits = array();
    }

    require_celerity_resource('syntax-highlighting-css');
    if ($can_highlight) {
      $highlighted = PhabricatorSyntaxHighlighter::highlightWithFilename(
        $path,
        $file_corpus);
    } else {
      // Highlight as plain text to escape the content properly.
      $highlighted = PhabricatorSyntaxHighlighter::highlightWithLanguage(
        'txt',
        $file_corpus);
    }

    $lines = phutil_split_lines($highlighted);

    $rows = $this->buildDisplayRows(
      $lines,
      $blame_list,
      $blame_commits,
      $show_blame);

    $corpus_table = javelin_tag(
      'table',
      array(
        'class' => 'diffusion-source remarkup-code PhabricatorMonospaced',
        'sigil' => 'phabricator-source',
      ),
      $rows);

    $corpus_table = phutil_tag_div('diffusion-source-wrap', $corpus_table);

    if ($this->getRequest()->isAjax()) {
      return $corpus_table;
    }

    $id = celerity_generate_unique_node_id();

    $repo = $drequest->getRepository();
    $symbol_repos = nonempty($repo->getSymbolSources(), array());
    $symbol_repos[] = $repo->getPHID();

    $lang = last(explode('.', $drequest->getPath()));
    $repo_languages = $repo->getSymbolLanguages();
    $repo_languages = nonempty($repo_languages, array());
    $repo_languages = array_fill_keys($repo_languages, true);

    $needs_symbols = true;
    if ($repo_languages && $symbol_repos) {
      $have_symbols = id(new DiffusionSymbolQuery())
          ->existsSymbolsInRepository($repo->getPHID());
      if (!$have_symbols) {
        $needs_symbols = false;
      }
    }

    if ($needs_symbols && $repo_languages) {
      $needs_symbols = isset($repo_languages[$lang]);
    }

    if ($needs_symbols) {
      Javelin::initBehavior(
        'repository-crossreference',
        array(
          'container' => $id,
          'lang' => $lang,
          'repositories' => $symbol_repos,
        ));
    }

    $corpus = phutil_tag(
      'div',
      array(
        'id' => $id,
      ),
      $corpus_table);

    Javelin::initBehavior('load-blame', array('id' => $id));

    $this->corpusButtons[] = $this->renderFileButton();
    $title = basename($this->getDiffusionRequest()->getPath());
    $icon = 'fa-file-code-o';
    $drequest = $this->getDiffusionRequest();
    $this->buildActionButtons($drequest);

    $header = $this->buildPanelHeaderView($title, $icon);

    $corpus = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->appendChild($corpus)
      ->addClass('diffusion-mobile-view')
      ->addSigil('diffusion-file-content-view')
      ->setMetadata(
        array(
          'path' => $this->getDiffusionRequest()->getPath(),
        ))
      ->setCollapsed(true);

    $messages = array();

    if (!$can_highlight) {
      $messages[] = pht(
        'This file is larger than %s, so syntax highlighting is disabled '.
        'by default.',
        phutil_format_bytes($highlight_limit));
    }

    if ($show_blame && !$can_blame) {
      $messages[] = pht(
        'This file is larger than %s, so blame is disabled.',
        phutil_format_bytes($blame_limit));
    }

    if ($blame_failed) {
      $messages[] = pht(
        'Failed to load blame information for this file in %s second(s).',
        new PhutilNumber($blame_timeout));
    }

    if ($messages) {
      $corpus->setInfoView(
        id(new PHUIInfoView())
          ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
          ->setErrors($messages));
    }

    return $corpus;
  }

  private function buildButtonBar(
    DiffusionRequest $drequest,
    $show_blame,
    $show_editor) {

    $viewer = $this->getViewer();
    $base_uri = $this->getRequest()->getRequestURI();

    $user = $this->getRequest()->getUser();
    $repository = $drequest->getRepository();
    $path = $drequest->getPath();
    $line = nonempty((int)$drequest->getLine(), 1);
    $buttons = array();

    $editor_link = $user->loadEditorLink($path, $line, $repository);
    $template = $user->loadEditorLink($path, '%l', $repository);

    $buttons[] =
      id(new PHUIButtonView())
        ->setTag('a')
        ->setText(pht('Last Change'))
        ->setColor(PHUIButtonView::GREY)
        ->setHref(
          $drequest->generateURI(
            array(
              'action' => 'change',
            )))
        ->setIcon('fa-backward');

    if ($show_blame) {
      $blame_text = pht('Disable Blame');
      $blame_icon = 'fa-exclamation-circle lightgreytext';
      $blame_value = 0;
    } else {
      $blame_text = pht('Enable Blame');
      $blame_icon = 'fa-exclamation-circle';
      $blame_value = 1;
    }

    $blame = id(new PHUIButtonView())
      ->setText($blame_text)
      ->setIcon($blame_icon)
      ->setUser($viewer)
      ->setSelected(!$blame_value)
      ->setColor(PHUIButtonView::GREY);

    if ($viewer->isLoggedIn()) {
      $blame = phabricator_form(
        $viewer,
        array(
          'action' => $base_uri->alter('blame', $blame_value),
          'method' => 'POST',
          'style' => 'display: inline-block;',
        ),
        $blame);
    } else {
      $blame->setTag('a');
      $blame->setHref($base_uri->alter('blame', $blame_value));
    }
    $buttons[] = $blame;

    if ($editor_link) {
      $buttons[] =
        id(new PHUIButtonView())
          ->setTag('a')
          ->setText(pht('Open File'))
          ->setHref($editor_link)
          ->setIcon('fa-pencil')
          ->setID('editor_link')
          ->setMetadata(array('link_template' => $template))
          ->setDisabled(!$editor_link)
          ->setColor(PHUIButtonView::GREY);
    }

    $href = null;
    $show_lint = true;
    if ($this->getRequest()->getStr('lint') !== null) {
      $lint_text = pht('Hide Lint');
      $href = $base_uri->alter('lint', null);

    } else if ($this->lintCommit === null) {
      $show_lint = false;
    } else {
      $lint_text = pht('Show Lint');
      $href = $this->getDiffusionRequest()->generateURI(array(
        'action' => 'browse',
        'commit' => $this->lintCommit,
      ))->alter('lint', '');
    }

    if ($show_lint) {
      $buttons[] =
        id(new PHUIButtonView())
          ->setTag('a')
          ->setText($lint_text)
          ->setHref($href)
          ->setIcon('fa-exclamation-triangle')
          ->setDisabled(!$href)
          ->setColor(PHUIButtonView::GREY);
    }

    $bar = id(new PHUILeftRightView())
      ->setLeft($buttons)
      ->addClass('diffusion-action-bar full-mobile-buttons');
    return $bar;
  }

  private function buildOwnersList(DiffusionRequest $drequest) {
    $viewer = $this->getViewer();

    $have_owners = PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorOwnersApplication',
      $viewer);
    if (!$have_owners) {
      return null;
    }

    $repository = $drequest->getRepository();

    $package_query = id(new PhabricatorOwnersPackageQuery())
      ->setViewer($viewer)
      ->withStatuses(array(PhabricatorOwnersPackage::STATUS_ACTIVE))
      ->withControl(
        $repository->getPHID(),
        array(
          $drequest->getPath(),
        ));

    $package_query->execute();

    $packages = $package_query->getControllingPackagesForPath(
      $repository->getPHID(),
      $drequest->getPath());

    $ownership = id(new PHUIObjectItemListView())
      ->setUser($viewer)
      ->setNoDataString(pht('No Owners'));

    if ($packages) {
      foreach ($packages as $package) {
        $item = id(new PHUIObjectItemView())
          ->setObject($package)
          ->setObjectName($package->getMonogram())
          ->setHeader($package->getName())
          ->setHref($package->getURI());

        $owners = $package->getOwners();
        if ($owners) {
          $owner_list = $viewer->renderHandleList(
            mpull($owners, 'getUserPHID'));
        } else {
          $owner_list = phutil_tag('em', array(), pht('None'));
        }
        $item->addAttribute(pht('Owners: %s', $owner_list));

        $auto = $package->getAutoReview();
        $autoreview_map = PhabricatorOwnersPackage::getAutoreviewOptionsMap();
        $spec = idx($autoreview_map, $auto, array());
        $name = idx($spec, 'name', $auto);
        $item->addIcon('fa-code', $name);

        if ($package->getAuditingEnabled()) {
          $item->addIcon('fa-check', pht('Auditing Enabled'));
        } else {
          $item->addIcon('fa-ban', pht('No Auditing'));
        }

        if ($package->isArchived()) {
          $item->setDisabled(true);
        }

        $ownership->addItem($item);
      }
    }

    $view = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Owner Packages'))
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->addClass('diffusion-mobile-view')
      ->setObjectList($ownership);

    return $view;
  }

  private function renderFileButton($file_uri = null, $label = null) {

    $base_uri = $this->getRequest()->getRequestURI();

    if ($file_uri) {
      $text = pht('Download File');
      $href = $file_uri;
      $icon = 'fa-download';
    } else {
      $text = pht('Raw File');
      $href = $base_uri->alter('view', 'raw');
      $icon = 'fa-file-text';
    }

    if ($label !== null) {
      $text = $label;
    }

    $button = id(new PHUIButtonView())
      ->setTag('a')
      ->setText($text)
      ->setHref($href)
      ->setIcon($icon)
      ->setColor(PHUIButtonView::GREY);

    return $button;
  }

  private function renderGitLFSButton() {
    $viewer = $this->getViewer();

    $uri = $this->getRequest()->getRequestURI();
    $href = $uri->alter('view', 'git-lfs');

    $text = pht('Download from Git LFS');
    $icon = 'fa-download';

    return id(new PHUIButtonView())
      ->setTag('a')
      ->setText($text)
      ->setHref($href)
      ->setIcon($icon)
      ->setColor(PHUIButtonView::GREY);
  }

  private function buildDisplayRows(
    array $lines,
    array $blame_list,
    array $blame_commits,
    $show_blame) {

    $request = $this->getRequest();
    $viewer = $this->getViewer();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $revision_map = array();
    $revisions = array();
    if ($blame_commits) {
      $commit_map = mpull($blame_commits, 'getCommitIdentifier', 'getPHID');

      $revision_ids = id(new DifferentialRevision())
        ->loadIDsByCommitPHIDs(array_keys($commit_map));
      if ($revision_ids) {
        $revisions = id(new DifferentialRevisionQuery())
          ->setViewer($viewer)
          ->withIDs($revision_ids)
          ->execute();
        $revisions = mpull($revisions, null, 'getID');
      }

      foreach ($revision_ids as $commit_phid => $revision_id) {
        // If the viewer can't actually see this revision, skip it.
        if (!isset($revisions[$revision_id])) {
          continue;
        }
        $revision_map[$commit_map[$commit_phid]] = $revision_id;
      }
    }

    $phids = array();
    foreach ($blame_commits as $commit) {
      $author_phid = $commit->getAuthorPHID();
      if ($author_phid === null) {
        continue;
      }
      $phids[$author_phid] = $author_phid;
    }

    foreach ($revisions as $revision) {
      $author_phid = $revision->getAuthorPHID();
      if ($author_phid === null) {
        continue;
      }
      $phids[$author_phid] = $author_phid;
    }

    $handles = $viewer->loadHandles($phids);

    $author_phids = array();
    $author_map = array();
    foreach ($blame_commits as $commit) {
      $commit_identifier = $commit->getCommitIdentifier();

      $author_phid = '';
      if (isset($revision_map[$commit_identifier])) {
        $revision_id = $revision_map[$commit_identifier];
        $revision = $revisions[$revision_id];
        $author_phid = $revision->getAuthorPHID();
      } else {
        $author_phid = $commit->getAuthorPHID();
      }

      $author_map[$commit_identifier] = $author_phid;
      $author_phids[$author_phid] = $author_phid;
    }

    $colors = array();
    if ($blame_commits) {
      $epochs = array();

      foreach ($blame_commits as $identifier => $commit) {
        $epochs[$identifier] = $commit->getEpoch();
      }

      $epoch_list = array_filter($epochs);
      $epoch_list = array_unique($epoch_list);
      $epoch_list = array_values($epoch_list);

      $epoch_min   = min($epoch_list);
      $epoch_max   = max($epoch_list);
      $epoch_range = ($epoch_max - $epoch_min) + 1;

      foreach ($blame_commits as $identifier => $commit) {
        $epoch = $epochs[$identifier];
        if (!$epoch) {
          $color = '#ffffdd'; // Warning color, missing data.
        } else {
          $color_ratio = ($epoch - $epoch_min) / $epoch_range;
          $color_value = 0xE6 * (1.0 - $color_ratio);
          $color = sprintf(
            '#%02x%02x%02x',
            $color_value,
            0xF6,
            $color_value);
        }

        $colors[$identifier] = $color;
      }
    }

    $display = array();
    $last_identifier = null;
    $last_color = null;
    foreach ($lines as $line_index => $line) {
      $color = '#f6f6f6';
      $duplicate = false;
      if (isset($blame_list[$line_index])) {
        $identifier = $blame_list[$line_index];
        if (isset($colors[$identifier])) {
          $color = $colors[$identifier];
        }

        if ($identifier === $last_identifier) {
          $duplicate = true;
        } else {
          $last_identifier = $identifier;
        }
      }

      $display[$line_index] = array(
        'data' => $line,
        'target' => false,
        'highlighted' => false,
        'color' => $color,
        'duplicate' => $duplicate,
      );
    }

    $line_arr = array();
    $line_str = $drequest->getLine();
    $ranges = explode(',', $line_str);
    foreach ($ranges as $range) {
      if (strpos($range, '-') !== false) {
        list($min, $max) = explode('-', $range, 2);
        $line_arr[] = array(
          'min' => min($min, $max),
          'max' => max($min, $max),
        );
      } else if (strlen($range)) {
        $line_arr[] = array(
          'min' => $range,
          'max' => $range,
        );
      }
    }

    // Mark the first highlighted line as the target line.
    if ($line_arr) {
      $target_line = $line_arr[0]['min'];
      if (isset($display[$target_line - 1])) {
        $display[$target_line - 1]['target'] = true;
      }
    }

    // Mark all other highlighted lines as highlighted.
    foreach ($line_arr as $range) {
      for ($ii = $range['min']; $ii <= $range['max']; $ii++) {
        if (isset($display[$ii - 1])) {
          $display[$ii - 1]['highlighted'] = true;
        }
      }
    }

    $engine = null;
    $inlines = array();
    if ($this->getRequest()->getStr('lint') !== null && $this->lintMessages) {
      $engine = new PhabricatorMarkupEngine();
      $engine->setViewer($viewer);

      foreach ($this->lintMessages as $message) {
        $inline = id(new PhabricatorAuditInlineComment())
          ->setSyntheticAuthor(
            ArcanistLintSeverity::getStringForSeverity($message['severity']).
            ' '.$message['code'].' ('.$message['name'].')')
          ->setLineNumber($message['line'])
          ->setContent($message['description']);
        $inlines[$message['line']][] = $inline;

        $engine->addObject(
          $inline,
          PhabricatorInlineCommentInterface::MARKUP_FIELD_BODY);
      }

      $engine->process();
      require_celerity_resource('differential-changeset-view-css');
    }

    $rows = $this->renderInlines(
      idx($inlines, 0, array()),
      $show_blame,
      (bool)$this->coverage,
      $engine);

    // NOTE: We're doing this manually because rendering is otherwise
    // dominated by URI generation for very large files.
    $line_base = (string)$drequest->generateURI(
      array(
        'action'  => 'browse',
        'stable'  => true,
      ));

    require_celerity_resource('aphront-tooltip-css');
    Javelin::initBehavior('phabricator-oncopy');
    Javelin::initBehavior('phabricator-tooltips');
    Javelin::initBehavior('phabricator-line-linker');

    // Render these once, since they tend to get repeated many times in large
    // blame outputs.
    $commit_links = $this->renderCommitLinks($blame_commits, $handles);
    $revision_links = $this->renderRevisionLinks($revisions, $handles);
    $author_links = $this->renderAuthorLinks($author_map, $handles);

    if ($this->coverage) {
      require_celerity_resource('differential-changeset-view-css');
      Javelin::initBehavior(
        'diffusion-browse-file',
        array(
          'labels' => array(
            'cov-C' => pht('Covered'),
            'cov-N' => pht('Not Covered'),
            'cov-U' => pht('Not Executable'),
          ),
        ));
    }

    $skip_text = pht('Skip Past This Commit');
    $skip_icon = id(new PHUIIconView())
      ->setIcon('fa-caret-square-o-left');

    foreach ($display as $line_index => $line) {
      $row = array();

      $line_number = $line_index + 1;
      $line_href = $line_base.'$'.$line_number;

      if (isset($blame_list[$line_index])) {
        $identifier = $blame_list[$line_index];
      } else {
        $identifier = null;
      }

      $revision_link = null;
      $commit_link = null;
      $author_link = null;
      $before_link = null;

      $style = 'background: '.$line['color'].';';

      if ($identifier && !$line['duplicate']) {
        if (isset($commit_links[$identifier])) {
          $commit_link = $commit_links[$identifier];
          $author_link = $author_links[$author_map[$identifier]];
        }

        if (isset($revision_map[$identifier])) {
          $revision_id = $revision_map[$identifier];
          if (isset($revision_links[$revision_id])) {
            $revision_link = $revision_links[$revision_id];
          }
        }

        $skip_href = $line_href.'?before='.$identifier.'&view=blame';
        $before_link = javelin_tag(
          'a',
          array(
            'href'  => $skip_href,
            'sigil' => 'has-tooltip',
            'meta'  => array(
              'tip'     => $skip_text,
              'align'   => 'E',
              'size'    => 300,
            ),
          ),
          $skip_icon);
      }

      if ($show_blame) {
        $row[] = phutil_tag(
          'th',
          array(
            'class' => 'diffusion-blame-link',
          ),
          $before_link);

        $object_links = array();
        $object_links[] = $author_link;
        $object_links[] = $commit_link;
        if ($revision_link) {
          $object_links[] = phutil_tag('span', array(), '/');
          $object_links[] = $revision_link;
        }

        $row[] = phutil_tag(
          'th',
          array(
            'class' => 'diffusion-rev-link',
          ),
          $object_links);
      }

      $line_link = phutil_tag(
        'a',
        array(
          'href' => $line_href,
          'style' => $style,
        ),
        $line_number);

      $row[] = javelin_tag(
        'th',
        array(
          'class' => 'diffusion-line-link',
          'sigil' => 'phabricator-source-line',
          'style' => $style,
        ),
        $line_link);

      if ($line['target']) {
        Javelin::initBehavior(
          'diffusion-jump-to',
          array(
            'target' => 'scroll_target',
          ));
        $anchor_text = phutil_tag(
          'a',
          array(
            'id' => 'scroll_target',
          ),
          '');
      } else {
        $anchor_text = null;
      }

      $row[] = phutil_tag(
        'td',
        array(
        ),
        array(
          $anchor_text,

          // NOTE: See phabricator-oncopy behavior.
          "\xE2\x80\x8B",

          // TODO: [HTML] Not ideal.
          phutil_safe_html(str_replace("\t", '  ', $line['data'])),
        ));

      if ($this->coverage) {
        $cov_index = $line_index;

        if (isset($this->coverage[$cov_index])) {
          $cov_class = $this->coverage[$cov_index];
        } else {
          $cov_class = 'N';
        }

        $row[] = phutil_tag(
          'td',
          array(
            'class' => 'cov cov-'.$cov_class,
          ),
          '');
      }

      $rows[] = phutil_tag(
        'tr',
        array(
          'class' => ($line['highlighted'] ?
                      'phabricator-source-highlight' :
                      null),
        ),
        $row);

      $cur_inlines = $this->renderInlines(
        idx($inlines, $line_number, array()),
        $show_blame,
        $this->coverage,
        $engine);
      foreach ($cur_inlines as $cur_inline) {
        $rows[] = $cur_inline;
      }
    }

    return $rows;
  }

  private function renderInlines(
    array $inlines,
    $show_blame,
    $has_coverage,
    $engine) {

    $rows = array();
    foreach ($inlines as $inline) {

      // TODO: This should use modern scaffolding code.

      $inline_view = id(new PHUIDiffInlineCommentDetailView())
        ->setUser($this->getViewer())
        ->setMarkupEngine($engine)
        ->setInlineComment($inline)
        ->render();

      $row = array_fill(0, ($show_blame ? 3 : 1), phutil_tag('th'));

      $row[] = phutil_tag('td', array(), $inline_view);

      if ($has_coverage) {
        $row[] = phutil_tag(
          'td',
          array(
            'class' => 'cov cov-I',
          ));
      }

      $rows[] = phutil_tag('tr', array('class' => 'inline'), $row);
    }

    return $rows;
  }

  private function buildImageCorpus($file_uri) {
    $properties = new PHUIPropertyListView();

    $properties->addImageContent(
      phutil_tag(
        'img',
        array(
          'src' => $file_uri,
        )));

    $this->corpusButtons[] = $this->renderFileButton($file_uri);
    $title = basename($this->getDiffusionRequest()->getPath());
    $icon = 'fa-file-image-o';
    $drequest = $this->getDiffusionRequest();
    $this->buildActionButtons($drequest);
    $header = $this->buildPanelHeaderView($title, $icon);

    return id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->addClass('diffusion-mobile-view')
      ->addPropertyList($properties);
  }

  private function buildBinaryCorpus($file_uri, $data) {
    $size = new PhutilNumber(strlen($data));
    $text = pht('This is a binary file. It is %s byte(s) in length.', $size);
    $text = id(new PHUIBoxView())
      ->addPadding(PHUI::PADDING_LARGE)
      ->appendChild($text);

    $this->corpusButtons[] = $this->renderFileButton($file_uri);
    $title = basename($this->getDiffusionRequest()->getPath());
    $icon = 'fa-file';
    $drequest = $this->getDiffusionRequest();
    $this->buildActionButtons($drequest);
    $header = $this->buildPanelHeaderView($title, $icon);

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->addClass('diffusion-mobile-view')
      ->appendChild($text);

    return $box;
  }

  private function buildErrorCorpus($message) {
    $text = id(new PHUIBoxView())
      ->addPadding(PHUI::PADDING_LARGE)
      ->appendChild($message);

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Details'));

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->appendChild($text);

    return $box;
  }

  private function buildBeforeResponse($before) {
    $request = $this->getRequest();
    $drequest = $this->getDiffusionRequest();

    // NOTE: We need to get the grandparent so we can capture filename changes
    // in the parent.

    $parent = $this->loadParentCommitOf($before);
    $old_filename = null;
    $was_created = false;
    if ($parent) {
      $grandparent = $this->loadParentCommitOf($parent);

      if ($grandparent) {
        $rename_query = new DiffusionRenameHistoryQuery();
        $rename_query->setRequest($drequest);
        $rename_query->setOldCommit($grandparent);
        $rename_query->setViewer($request->getUser());
        $old_filename = $rename_query->loadOldFilename();
        $was_created = $rename_query->getWasCreated();
      }
    }

    $follow = null;
    if ($was_created) {
      // If the file was created in history, that means older commits won't
      // have it. Since we know it existed at 'before', it must have been
      // created then; jump there.
      $target_commit = $before;
      $follow = 'created';
    } else if ($parent) {
      // If we found a parent, jump to it. This is the normal case.
      $target_commit = $parent;
    } else {
      // If there's no parent, this was probably created in the initial commit?
      // And the "was_created" check will fail because we can't identify the
      // grandparent. Keep the user at 'before'.
      $target_commit = $before;
      $follow = 'first';
    }

    $path = $drequest->getPath();
    $renamed = null;
    if ($old_filename !== null &&
        $old_filename !== '/'.$path) {
      $renamed = $path;
      $path = $old_filename;
    }

    $line = null;
    // If there's a follow error, drop the line so the user sees the message.
    if (!$follow) {
      $line = $this->getBeforeLineNumber($target_commit);
    }

    $before_uri = $drequest->generateURI(
      array(
        'action'    => 'browse',
        'commit'    => $target_commit,
        'line'      => $line,
        'path'      => $path,
      ));

    $before_uri->setQueryParams($request->getRequestURI()->getQueryParams());
    $before_uri = $before_uri->alter('before', null);
    $before_uri = $before_uri->alter('renamed', $renamed);
    $before_uri = $before_uri->alter('follow', $follow);

    return id(new AphrontRedirectResponse())->setURI($before_uri);
  }

  private function getBeforeLineNumber($target_commit) {
    $drequest = $this->getDiffusionRequest();
    $viewer = $this->getViewer();

    $line = $drequest->getLine();
    if (!$line) {
      return null;
    }

    $diff_info = $this->callConduitWithDiffusionRequest(
      'diffusion.rawdiffquery',
      array(
        'commit' => $drequest->getCommit(),
        'path' => $drequest->getPath(),
        'againstCommit' => $target_commit,
      ));

    $file_phid = $diff_info['filePHID'];
    $file = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($file_phid))
      ->executeOne();
    if (!$file) {
      throw new Exception(
        pht(
          'Failed to load file ("%s") returned by "%s".',
          $file_phid,
          'diffusion.rawdiffquery.'));
    }

    $raw_diff = $file->loadFileData();

    $old_line = 0;
    $new_line = 0;

    foreach (explode("\n", $raw_diff) as $text) {
      if ($text[0] == '-' || $text[0] == ' ') {
        $old_line++;
      }
      if ($text[0] == '+' || $text[0] == ' ') {
        $new_line++;
      }
      if ($new_line == $line) {
        return $old_line;
      }
    }

    // We didn't find the target line.
    return $line;
  }

  private function loadParentCommitOf($commit) {
    $drequest = $this->getDiffusionRequest();
    $user = $this->getRequest()->getUser();

    $before_req = DiffusionRequest::newFromDictionary(
      array(
        'user' => $user,
        'repository' => $drequest->getRepository(),
        'commit' => $commit,
      ));

    $parents = DiffusionQuery::callConduitWithDiffusionRequest(
      $user,
      $before_req,
      'diffusion.commitparentsquery',
      array(
        'commit' => $commit,
      ));

    return head($parents);
  }

  private function renderRevisionTooltip(
    DifferentialRevision $revision,
    $handles) {
    $viewer = $this->getRequest()->getUser();

    $date = phabricator_date($revision->getDateModified(), $viewer);
    $id = $revision->getID();
    $title = $revision->getTitle();
    $header = "D{$id} {$title}";

    $author = $handles[$revision->getAuthorPHID()]->getName();

    return "{$header}\n{$date} \xC2\xB7 {$author}";
  }

  private function renderCommitTooltip(
    PhabricatorRepositoryCommit $commit,
    $author) {

    $viewer = $this->getRequest()->getUser();

    $date = phabricator_date($commit->getEpoch(), $viewer);
    $summary = trim($commit->getSummary());

    return "{$summary}\n{$date} \xC2\xB7 {$author}";
  }

  protected function markupText($text) {
    $engine = PhabricatorMarkupEngine::newDiffusionMarkupEngine();
    $engine->setConfig('viewer', $this->getRequest()->getUser());
    $text = $engine->markupText($text);

    $text = phutil_tag(
      'div',
      array(
        'class' => 'phabricator-remarkup',
      ),
      $text);

    return $text;
  }

  protected function buildHeaderView(DiffusionRequest $drequest) {
    $viewer = $this->getViewer();
    $repository = $drequest->getRepository();

    $commit_tag = $this->renderCommitHashTag($drequest);

    $path = nonempty($drequest->getPath(), '/');

    $search = $this->renderSearchForm($path);

    $header = id(new PHUIHeaderView())
      ->setUser($viewer)
      ->setHeader($this->renderPathLinks($drequest, $mode = 'browse'))
      ->addActionItem($search)
      ->addTag($commit_tag)
      ->addClass('diffusion-browse-header');

    if (!$repository->isSVN()) {
      $branch_tag = $this->renderBranchTag($drequest);
      $header->addTag($branch_tag);
    }

    return $header;
  }

  protected function buildPanelHeaderView($title, $icon) {

    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setHeaderIcon($icon)
      ->addClass('diffusion-panel-header-view');

    return $header;

  }

  protected function buildActionButtons(
    DiffusionRequest $drequest,
    $is_directory = false) {

    $viewer = $this->getViewer();
    $repository = $drequest->getRepository();
    $history_uri = $drequest->generateURI(array('action' => 'history'));
    $behind_head = $drequest->getSymbolicCommit();
    $compare = null;
    $head_uri = $drequest->generateURI(
      array(
        'commit' => '',
        'action' => 'browse',
      ));

    if ($repository->supportsBranchComparison() && $is_directory) {
      $compare_uri = $drequest->generateURI(array('action' => 'compare'));
      $compare = id(new PHUIButtonView())
        ->setText(pht('Compare'))
        ->setIcon('fa-code-fork')
        ->setWorkflow(true)
        ->setTag('a')
        ->setHref($compare_uri)
        ->setColor(PHUIButtonView::GREY);
      $this->corpusButtons[] = $compare;
    }

    $head = null;
    if ($behind_head) {
      $head = id(new PHUIButtonView())
        ->setTag('a')
        ->setText(pht('Back to HEAD'))
        ->setHref($head_uri)
        ->setIcon('fa-home')
        ->setColor(PHUIButtonView::GREY);
      $this->corpusButtons[] = $head;
    }

    $history = id(new PHUIButtonView())
      ->setText(pht('History'))
      ->setHref($history_uri)
      ->setTag('a')
      ->setIcon('fa-history')
      ->setColor(PHUIButtonView::GREY);
    $this->corpusButtons[] = $history;

  }

  protected function buildPropertyView(
    DiffusionRequest $drequest) {

    $viewer = $this->getViewer();
    $view = id(new PHUIPropertyListView())
      ->setUser($viewer);

    if ($drequest->getSymbolicType() == 'tag') {
      $symbolic = $drequest->getSymbolicCommit();
      $view->addProperty(pht('Tag'), $symbolic);

      $tags = $this->callConduitWithDiffusionRequest(
        'diffusion.tagsquery',
        array(
          'names' => array($symbolic),
          'needMessages' => true,
        ));
      $tags = DiffusionRepositoryTag::newFromConduit($tags);

      $tags = mpull($tags, null, 'getName');
      $tag = idx($tags, $symbolic);

      if ($tag && strlen($tag->getMessage())) {
        $view->addSectionHeader(
          pht('Tag Content'), 'fa-tag');
        $view->addTextContent($this->markupText($tag->getMessage()));
      }
    }

    if ($view->hasAnyProperties()) {
      return $view;
    }

    return null;
  }

  private function buildOpenRevisions() {
    $viewer = $this->getViewer();

    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();
    $path = $drequest->getPath();

    $path_map = id(new DiffusionPathIDQuery(array($path)))->loadPathIDs();
    $path_id = idx($path_map, $path);
    if (!$path_id) {
      return null;
    }

    $recent = (PhabricatorTime::getNow() - phutil_units('30 days in seconds'));

    $revisions = id(new DifferentialRevisionQuery())
      ->setViewer($viewer)
      ->withPath($repository->getID(), $path_id)
      ->withIsOpen(true)
      ->withUpdatedEpochBetween($recent, null)
      ->setOrder(DifferentialRevisionQuery::ORDER_MODIFIED)
      ->setLimit(10)
      ->needReviewers(true)
      ->needFlags(true)
      ->needDrafts(true)
      ->execute();

    if (!$revisions) {
      return null;
    }

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Recently Open Revisions'));

    $list = id(new DifferentialRevisionListView())
      ->setRevisions($revisions)
      ->setUser($viewer)
      ->setNoBox(true);

    $phids = $list->getRequiredHandlePHIDs();
    $handles = $this->loadViewerHandles($phids);
    $list->setHandles($handles);

    $view = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->addClass('diffusion-mobile-view')
      ->appendChild($list);

    return $view;
  }

  private function loadBlame($path, $commit, $timeout) {
    $blame = $this->callConduitWithDiffusionRequest(
      'diffusion.blame',
      array(
        'commit' => $commit,
        'paths' => array($path),
        'timeout' => $timeout,
      ));

    $identifiers = idx($blame, $path, null);

    if ($identifiers) {
      $viewer = $this->getViewer();
      $drequest = $this->getDiffusionRequest();
      $repository = $drequest->getRepository();

      $commits = id(new DiffusionCommitQuery())
        ->setViewer($viewer)
        ->withRepository($repository)
        ->withIdentifiers($identifiers)
        // TODO: We only fetch this to improve author display behavior, but
        // shouldn't really need to?
        ->needCommitData(true)
        ->execute();
      $commits = mpull($commits, null, 'getCommitIdentifier');
    } else {
      $commits = array();
    }

    return array($identifiers, $commits);
  }

  private function renderAuthorLinks(array $authors, $handles) {
    $links = array();

    foreach ($authors as $phid) {
      if (!strlen($phid)) {
        // This means we couldn't identify an author for the commit or the
        // revision. We just render a blank for alignment.
        $style = null;
        $href = null;
        $sigil = null;
        $meta = null;
      } else {
        $src = $handles[$phid]->getImageURI();
        $style = 'background-image: url('.$src.');';
        $href = $handles[$phid]->getURI();
        $sigil = 'has-tooltip';
        $meta = array(
          'tip' => $handles[$phid]->getName(),
          'align' => 'E',
        );
      }

      $links[$phid] = javelin_tag(
        $href ? 'a' : 'span',
        array(
          'class' => 'diffusion-author-link',
          'style' => $style,
          'href' => $href,
          'sigil' => $sigil,
          'meta' => $meta,
        ));
    }

    return $links;
  }

  private function renderCommitLinks(array $commits, $handles) {
    $links = array();
    foreach ($commits as $identifier => $commit) {
      $tooltip = $this->renderCommitTooltip(
        $commit,
        $commit->renderAuthorShortName($handles));

      $commit_link = javelin_tag(
        'a',
        array(
          'href' => $commit->getURI(),
          'sigil' => 'has-tooltip',
          'meta'  => array(
            'tip'   => $tooltip,
            'align' => 'E',
            'size'  => 600,
          ),
        ),
        $commit->getLocalName());

      $links[$identifier] = $commit_link;
    }

    return $links;
  }

  private function renderRevisionLinks(array $revisions, $handles) {
    $links = array();

    foreach ($revisions as $revision) {
      $revision_id = $revision->getID();

      $tooltip = $this->renderRevisionTooltip($revision, $handles);

      $revision_link = javelin_tag(
        'a',
        array(
          'href' => '/'.$revision->getMonogram(),
          'sigil' => 'has-tooltip',
          'meta'  => array(
            'tip'   => $tooltip,
            'align' => 'E',
            'size'  => 600,
          ),
        ),
        $revision->getMonogram());

      $links[$revision_id] = $revision_link;
    }

    return $links;
  }

  private function getGitLFSRef(PhabricatorRepository $repository, $data) {
    if (!$repository->canUseGitLFS()) {
      return null;
    }

    $lfs_pattern = '(^version https://git-lfs\\.github\\.com/spec/v1[\r\n])';
    if (!preg_match($lfs_pattern, $data)) {
      return null;
    }

    $matches = null;
    if (!preg_match('(^oid sha256:(.*)$)m', $data, $matches)) {
      return null;
    }

    $hash = $matches[1];
    $hash = trim($hash);

    return id(new PhabricatorRepositoryGitLFSRefQuery())
      ->setViewer($this->getViewer())
      ->withRepositoryPHIDs(array($repository->getPHID()))
      ->withObjectHashes(array($hash))
      ->executeOne();
  }

  private function buildGitLFSCorpus(PhabricatorRepositoryGitLFSRef $ref) {
    // TODO: We should probably test if we can load the file PHID here and
    // show the user an error if we can't, rather than making them click
    // through to hit an error.

    $title = basename($this->getDiffusionRequest()->getPath());
    $icon = 'fa-archive';
    $drequest = $this->getDiffusionRequest();
    $this->buildActionButtons($drequest);
    $header = $this->buildPanelHeaderView($title, $icon);

    $severity = PHUIInfoView::SEVERITY_NOTICE;

    $messages = array();
    $messages[] = pht(
      'This %s file is stored in Git Large File Storage.',
      phutil_format_bytes($ref->getByteSize()));

    try {
      $file = $this->loadGitLFSFile($ref);
      $this->corpusButtons[] = $this->renderGitLFSButton();
    } catch (Exception $ex) {
      $severity = PHUIInfoView::SEVERITY_ERROR;
      $messages[] = pht('The data for this file could not be loaded.');
    }

    $this->corpusButtons[] = $this->renderFileButton(
      null, pht('View Raw LFS Pointer'));

    $corpus = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->addClass('diffusion-mobile-view')
      ->setCollapsed(true);

    if ($messages) {
      $corpus->setInfoView(
        id(new PHUIInfoView())
          ->setSeverity($severity)
          ->setErrors($messages));
    }

    return $corpus;
  }

  private function loadGitLFSFile(PhabricatorRepositoryGitLFSRef $ref) {
    $viewer = $this->getViewer();

    $file = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($ref->getFilePHID()))
      ->executeOne();
    if (!$file) {
      throw new Exception(
        pht(
          'Failed to load file object for Git LFS ref "%s"!',
          $ref->getObjectHash()));
    }

    return $file;
  }

  private function buildBranchTable() {
    $viewer = $this->getViewer();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $branch = $drequest->getBranch();
    $default_branch = $repository->getDefaultBranch();

    if ($branch === $default_branch) {
      return null;
    }

    $pager = id(new PHUIPagerView())
      ->setPageSize(10);

    try {
      $results = $this->callConduitWithDiffusionRequest(
        'diffusion.historyquery',
        array(
          'commit' => $branch,
          'against' => $default_branch,
          'path' => $drequest->getPath(),
          'offset' => $pager->getOffset(),
          'limit' => $pager->getPageSize() + 1,
        ));
    } catch (Exception $ex) {
      return null;
    }

    $history = DiffusionPathChange::newFromConduit($results['pathChanges']);
    $history = $pager->sliceResults($history);

    if (!$history) {
      return null;
    }

    $history_table = id(new DiffusionHistoryTableView())
      ->setViewer($viewer)
      ->setDiffusionRequest($drequest)
      ->setHistory($history);

    $history_table->loadRevisions();

    $history_table
      ->setParents($results['parents'])
      ->setFilterParents(true)
      ->setIsHead(true)
      ->setIsTail(!$pager->getHasMorePages());

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('%s vs %s', $branch, $default_branch));

    return id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->addClass('diffusion-mobile-view')
      ->setTable($history_table);
  }

}
