<?php

final class PhabricatorAuditListView extends AphrontView {

  private $commits;
  private $handles;
  private $authorityPHIDs = array();
  private $noDataString;

  private $highlightedAudits;

  private $commitAudits = array();
  private $commitAuditorsHTML = array();

  public function setHandles(array $handles) {
    assert_instances_of($handles, 'PhabricatorObjectHandle');
    $this->handles = $handles;
    return $this;
  }

  public function setAuthorityPHIDs(array $phids) {
    $this->authorityPHIDs = $phids;
    return $this;
  }

  public function setNoDataString($no_data_string) {
    $this->noDataString = $no_data_string;
    return $this;
  }

  public function getNoDataString() {
    return $this->noDataString;
  }

  /**
   * These commits should have both commit data and audit requests attached.
   */
  public function setCommits(array $commits) {
    assert_instances_of($commits, 'PhabricatorRepositoryCommit');
    $this->commits = mpull($commits, null, 'getPHID');
    return $this;
  }

  public function getCommits() {
    return $this->commits;
  }

  public function getRequiredHandlePHIDs() {
    $phids = array();
    $commits = $this->getCommits();
    foreach ($commits as $commit) {
      $phids[$commit->getPHID()] = true;
      $phids[$commit->getAuthorPHID()] = true;
      $audits = $commit->getAudits();
      foreach ($audits as $audit) {
        $phids[$audit->getAuditorPHID()] = true;
      }
    }
    return array_keys($phids);
  }

  private function getHandle($phid) {
    $handle = idx($this->handles, $phid);
    if (!$handle) {
      throw new Exception("No handle for '{$phid}'!");
    }
    return $handle;
  }

  private function getCommitDescription($phid) {
    if ($this->commits === null) {
      return pht('(Unknown Commit)');
    }

    $commit = idx($this->commits, $phid);
    if (!$commit) {
      return pht('(Unknown Commit)');
    }

    $summary = $commit->getCommitData()->getSummary();
    if (strlen($summary)) {
      return $summary;
    }

    // No summary, so either this is still impoting or just has an empty
    // commit message.

    if (!$commit->isImported()) {
      return pht('(Importing Commit...)');
    } else {
      return pht('(Untitled Commit)');
    }
  }

  public function render() {
    $list = $this->buildList();
    $list->setFlush(true);
    return $list->render();
  }

  public function buildList() {
    $user = $this->getUser();
    if (!$user) {
      throw new Exception('you must setUser() before buildList()!');
    }
    $rowc = array();

    $this->prepareAuditorInformation();
    $modification_dates = $this->getCommitsDateModified();

    $fresh = PhabricatorEnv::getEnvConfig('differential.days-fresh');
    if ($fresh) {
      $fresh = PhabricatorCalendarHoliday::getNthBusinessDay(
        time(),
        -$fresh);
    }

    $stale = PhabricatorEnv::getEnvConfig('differential.days-stale');
    if ($stale) {
      $stale = PhabricatorCalendarHoliday::getNthBusinessDay(
        time(),
        -$stale);
    }

    $this->initBehavior('phabricator-tooltips', array());
    $this->requireResource('aphront-tooltip-css');

    $draft_icon = id(new PHUIIconView())
      ->setIconFont('fa-comment yellow')
      ->addSigil('has-tooltip')
      ->setMetadata(
        array(
          'tip' => pht('Unsubmitted Comments'),
        ));

    $list = new PHUIObjectItemListView();
    foreach ($this->commits as $commit) {
      $commit_phid = $commit->getPHID();
      $commit_handle = $this->getHandle($commit_phid);

      $commit_name = $commit_handle->getName();
      $commit_link = $commit_handle->getURI();
      $commit_desc = $this->getCommitDescription($commit_phid);

      $audit = idx($this->commitAudits, $commit_phid);
      if ($audit) {
        $reasons = $audit->getAuditReasons();
        $reasons = phutil_implode_html(', ', $reasons);
        $status_code = $audit->getAuditStatus();
        $status_text =
          PhabricatorAuditStatusConstants::getStatusName($status_code);
        $status_color =
          PhabricatorAuditStatusConstants::getStatusColor($status_code);
      } else {
        $reasons = null;
        $status_text = null;
        $status_color = null;
      }
      $author_name = $commit->getCommitData()->getAuthorName();

      $item = id(new PHUIObjectItemView())
        ->setUser($user)
        ->setObjectName($commit_name)
        ->setHeader($commit_desc)
        ->setHref($commit_link)
        ->setBarColor($status_color);

      if ($commit->getDrafts($user)) {
        $item->addAttribute($draft_icon);
      }

      $item
        ->addAttribute($status_text)
        ->addAttribute($reasons);

      if (idx($modification_dates, $commit_phid)) {
        $modified = $modification_dates[$commit_phid];

        if ($stale && $modified < $stale) {
          $object_age = PHUIObjectItemView::AGE_OLD;
        } else if ($fresh && $modified < $fresh) {
          $object_age = PHUIObjectItemView::AGE_STALE;
        } else {
          $object_age = PHUIObjectItemView::AGE_FRESH;
        }

        $item->setEpoch($modified, $object_age);
      } else {
        $item->setEpoch($commit->getEpoch());
      }

      // Author
      $author_phid = $commit->getAuthorPHID();

      if ($author_phid !== null) {
        $author_name = $this->getHandle($author_phid)->renderLink();
      }

      $item->addByline(pht('Author: %s', $author_name));

      $auditors = idx($this->commitAuditorsHTML, $commit_phid, array());
      if (!empty($auditors)) {
        $item->addAttribute(pht('Auditors: %s', $auditors));
      }

      $list->addItem($item);
    }

    if ($this->noDataString) {
      $list->setNoDataString($this->noDataString);
    }

    return $list;
  }

  private function prepareAuditorInformation() {
    foreach ($this->commits as $commit) {
      $auditors = array();
      $audits = mpull($commit->getAudits(), null, 'getAuditorPHID');

      foreach ($audits as $audit) {
        $auditor_phid = $audit->getAuditorPHID();
        $auditors[$auditor_phid] =
          $this->getHandle($auditor_phid)->renderLink();
      }

      $commit_phid = $commit->getPHID();
      $this->commitAuditorsHTML[$commit_phid] =
        phutil_implode_html(', ', $auditors);

      $authority_audits = array_select_keys($audits, $this->authorityPHIDs);
      if ($authority_audits) {
        $this->commitAudits[$commit_phid] = reset($authority_audits);
      } else {
        $this->commitAudits[$commit_phid] = reset($audits);
      }
    }

    $this->commitAuditorsHTML = array_filter($this->commitAuditorsHTML);
    $this->commitAudits = array_filter($this->commitAudits);
  }

  private function getCommitsDateModified() {
    $commit_phids = array();
    $modification_dates = array();

    $statuses = array(
      PhabricatorAuditStatusConstants::AUDIT_REQUIRED => true,
      PhabricatorAuditStatusConstants::CONCERNED => true,
      PhabricatorAuditStatusConstants::AUDIT_REQUESTED => true,
    );

    foreach ($this->commitAudits as $commit_phid => $audit) {
      if (idx($statuses, $audit->getAuditStatus())) {
        $commit_phids[] = $commit_phid;

        // Allows to handle old commits without transactions.
        $modification_dates[$commit_phid] =
          $this->commits[$commit_phid]->getEpoch();
      }
    }

    if (!$commit_phids) {
      return array();
    }

    $xactions = id(new PhabricatorAuditTransactionQuery())
      ->setViewer($this->getUser())
      ->withObjectPHIDs($commit_phids)
      ->withTransactionTypes(array(
        PhabricatorAuditActionConstants::ADD_AUDITORS,
        PhabricatorAuditActionConstants::ACTION,
      ))
      ->needComments(true)
      ->execute();

    // Constants of "PhabricatorAuditActionConstants" class can
    // tell audit result of each transaction.
    foreach ($xactions as $xaction) {
      $modification_dates[$xaction->getObjectPHID()] =
        $xaction->getDateModified();
    }

    return $modification_dates;
  }

}
