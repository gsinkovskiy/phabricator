<?php

final class DiffusionRepositoryEditSubversionController
  extends DiffusionRepositoryEditController {

  public function handleRequest(AphrontRequest $request) {
    $response = $this->loadDiffusionContextForEdit();
    if ($response) {
      return $response;
    }

    $viewer = $this->getViewer();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    switch ($repository->getVersionControlSystem()) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        throw new Exception(
          pht('Git and Mercurial do not support editing SVN properties!'));
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        break;
      default:
        throw new Exception(
          pht('Repository has unknown version control system!'));
    }

    $edit_uri = $this->getRepositoryControllerURI($repository, 'edit/');

    $v_subpath = $repository->getHumanReadableDetail('svn-subpath');
    $v_layout = $repository->getSubversionLayout();
    $v_trunk_folder = $repository->getHumanReadableDetail('svn-trunk-folder');
    $v_branches_folder =
      $repository->getHumanReadableDetail('svn-branches-folder');
    $v_tags_folder = $repository->getHumanReadableDetail('svn-tags-folder');
    $v_uuid = $repository->getUUID();

    if ($request->isFormPost()) {
      $v_subpath = $request->getStr('subpath');
      $v_layout = $request->getStr('layout');
      $v_trunk_folder = $request->getStr('trunk_folder');
      $v_branches_folder = $request->getStr('branches_folder');
      $v_tags_folder = $request->getStr('tags_folder');
      $v_uuid = $request->getStr('uuid');

      $xactions = array();
      $template = id(new PhabricatorRepositoryTransaction());

      $type_subpath = PhabricatorRepositoryTransaction::TYPE_SVN_SUBPATH;
      $type_layout = PhabricatorRepositoryTransaction::TYPE_SVN_LAYOUT;
      $type_trunk_folder =
        PhabricatorRepositoryTransaction::TYPE_SVN_TRUNK_FOLDER;
      $type_branches_folder =
        PhabricatorRepositoryTransaction::TYPE_SVN_BRANCHES_FOLDER;
      $type_tags_folder =
        PhabricatorRepositoryTransaction::TYPE_SVN_TAGS_FOLDER;
      $type_uuid = PhabricatorRepositoryTransaction::TYPE_UUID;

      $xactions[] = id(clone $template)
        ->setTransactionType($type_subpath)
        ->setNewValue($v_subpath);

      $xactions[] = id(clone $template)
        ->setTransactionType($type_layout)
        ->setNewValue($v_layout);

      $xactions[] = id(clone $template)
        ->setTransactionType($type_trunk_folder)
        ->setNewValue($v_trunk_folder);

      $xactions[] = id(clone $template)
        ->setTransactionType($type_branches_folder)
        ->setNewValue($v_branches_folder);

      $xactions[] = id(clone $template)
        ->setTransactionType($type_tags_folder)
        ->setNewValue($v_tags_folder);

      $xactions[] = id(clone $template)
        ->setTransactionType($type_uuid)
        ->setNewValue($v_uuid);

      id(new PhabricatorRepositoryEditor())
        ->setContinueOnNoEffect(true)
        ->setContentSourceFromRequest($request)
        ->setActor($viewer)
        ->applyTransactions($repository, $xactions);

      return id(new AphrontRedirectResponse())->setURI($edit_uri);
    }

    $content = array();

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Edit Subversion Info'));

    $title = pht('Edit Subversion Info (%s)', $repository->getName());
    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setHeaderIcon('fa-pencil');

    $policies = id(new PhabricatorPolicyQuery())
      ->setViewer($viewer)
      ->setObject($repository)
      ->execute();

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->appendRemarkupInstructions(
        pht(
          "You can set the **Repository UUID**, which will help Phabriactor ".
          "provide better context in some cases. You can find the UUID of a ".
          "repository by running `%s`.\n\n".
          "If you want to import only part of a repository, like `trunk/`, ".
          "you can set a path in **Import Only**. Phabricator will ignore ".
          "commits which do not affect this path.".
          "\n\n".
          "If a repository has a **Layout** (e.g. `/trunk/..., ".
          "/branches/NAME/..., /tags/NAME/...``), then it needs to be ".
          "specified to enable detection of branches and tags.".
          "\n\n".
          "By setting **Layout** to **Custom** it's possible to account for ".
          "non-standard repository folder names by changing **Trunk Folder**, ".
          "**Branches Folder** and **Tags Folder** settings.",
          'svn info'))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('uuid')
          ->setLabel(pht('Repository UUID'))
          ->setValue($v_uuid))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('subpath')
          ->setLabel(pht('Import Only'))
          ->setValue($v_subpath))
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setName('layout')
          ->setLabel(pht('Layout'))
          ->setValue($v_layout)
          ->setOptions(
            array(
              PhabricatorRepository::LAYOUT_NONE => pht('None'),
              PhabricatorRepository::LAYOUT_STANDARD => pht('Standard'),
              PhabricatorRepository::LAYOUT_CUSTOM => pht('Custom'),)))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('trunk_folder')
          ->setLabel(pht('Trunk Folder'))
          ->setValue($v_trunk_folder))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('branches_folder')
          ->setLabel(pht('Branches Folder'))
          ->setValue($v_branches_folder))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('tags_folder')
          ->setLabel(pht('Tags Folder'))
          ->setValue($v_tags_folder))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Save Subversion Info'))
          ->addCancelButton($edit_uri));

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Subversion'))
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->setForm($form);

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setFooter(array(
        $form_box,
      ));

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->appendChild($view);
  }

}
