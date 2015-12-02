<?php

final class PhabricatorAuditListView extends AphrontView {

  private $commits;
  private $handles;
  private $authorityPHIDs = array();
  private $noDataString;

  private $highlightedAudits;

  private $commitAudits = array();
  private $commitAuditorsHTML = array();

  private $transactions = array();
  private $inverseMentionMap = array();
  private $inverseMentionCommits = array();

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
      throw new Exception(pht("No handle for '%s'!", $phid));
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

    $summary = $commit->getSummary();
    if (strlen($summary)) {
      return $summary;
    }

    // No summary, so either this is still importing or just has an empty
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
      throw new Exception(
        pht(
          'You must %s before %s!',
          'setUser()',
          __FUNCTION__.'()'));
    }
    $rowc = array();

    $this->prepareTransactions();
    $this->prepareAuditorInformation();
    $this->prepareInverseMentions();

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

    $fix_icon = id(new PHUIIconView())
      ->setIconFont('fa-wrench green')
      ->addSigil('has-tooltip');

    $fixed_by_icon = id(new PHUIIconView())
      ->setIconFont('fa-medkit green')
      ->addSigil('has-tooltip');

    $list = new PHUIObjectItemListView();
    foreach ($this->commits as $commit) {
      $commit_phid = $commit->getPHID();
      $commit_handle = $this->getHandle($commit_phid);

      $commit_name = $commit_handle->getName();
      $commit_link = $commit_handle->getURI();
      $commit_desc = $this->getCommitDescription($commit_phid);

      $commit_prefix = '';
      if ($commit->getCommitType() == PhabricatorCommitType::COMMIT_FIX) {
        list ($commit_prefix, $commit_desc) =
          $commit->parseCommitMessage($commit_desc);
      }

      $audit = idx($this->commitAudits, $commit_phid);
      if ($audit) {
        $reasons = $audit->getAuditReasons();
        $reasons = phutil_implode_html(', ', $reasons);
        $status_code = $audit->getAuditStatus();
        $status_text =
          PhabricatorAuditStatusConstants::getStatusName($status_code);
        $status_color =
          PhabricatorAuditStatusConstants::getStatusColor($status_code);
        $status_icon =
          PhabricatorAuditStatusConstants::getStatusIcon($status_code);
      } else {
        $reasons = null;
        $status_code = null;
        $status_text = null;
        $status_color = null;
        $status_icon = null;
      }

      $item = id(new PHUIObjectItemView())
        ->setUser($user)
        ->setObjectName($commit_name)
        ->setHeader($commit_desc)
        ->setHref($commit_link);

      // Add icon indicating, that this commit is a fix commit.
      if ($commit_prefix) {
        $actual_fix_icon = clone $fix_icon;
        $actual_fix_icon->setMetadata(
          array(
            'tip' => ucfirst($commit_prefix),
          ));

        $item->addAttribute($actual_fix_icon);
      }

      // Add icon indicating, that this commit is fixed by other commit(-s).
      $fixed_in = $this->getFixCommits($commit_handle);
      if ($fixed_in) {
        $actual_fixed_by_icon = clone $fixed_by_icon;
        $actual_fixed_by_icon->setMetadata(
          array(
            'tip' => 'Fixed in: '.implode(', ', $fixed_in),
          ));

        $item->addAttribute($actual_fixed_by_icon);
      }

      $flag = $commit->getFlag($user);
      if ($flag) {
        $flag_class = PhabricatorFlagColor::getCSSClass($flag->getColor());
        $flag_icon = javelin_tag(
          'div',
          array(
            'sigil' => 'has-tooltip',
            'meta' => array(
              'tip' => $flag->getNote(),
            ),
            'class' => 'phabricator-flag-icon '.$flag_class,
          ),
          '');

        $item->addHeadIcon($flag_icon);
      }

      if ($commit->getDrafts($user)) {
        $item->addAttribute($draft_icon);
      }

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
      } else {
        $author_name = $commit->getCommitData()->getAuthorName();
      }

      $item->addAttribute(pht('Author: %s', $author_name));
      $item->addAttribute($reasons);

      $auditors = idx($this->commitAuditorsHTML, $commit_phid, array());
      if (!empty($auditors)) {
        $item->addByLine(pht('Auditors: %s', $auditors));
      }

      if ($status_color) {
        $item->setStatusIcon(
          $status_icon.' '.$status_color, $status_text);
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
    $modification_dates = $this->getCommitDatesWithOpenAudits();

    if (!$modification_dates) {
      return array();
    }

    $watch_types = array(
      PhabricatorAuditActionConstants::ADD_AUDITORS,
      PhabricatorAuditActionConstants::ACTION,);

    foreach ($this->transactions as $xaction) {
      if (in_array($xaction->getTransactionType(), $watch_types)) {
        $commit_phid = $xaction->getObjectPHID();
        $transaction_date = $xaction->getDateModified();
        $last_transaction_date = idx($modification_dates, $commit_phid, 0);

        if ($transaction_date > $last_transaction_date) {
          $modification_dates[$commit_phid] = $transaction_date;
        }
      }
    }

    return $modification_dates;
  }

  private function getCommitDatesWithOpenAudits() {
    $commit_dates = array();
    $statuses = PhabricatorAuditStatusConstants::getOpenStatusConstants();

    foreach ($this->commitAudits as $commit_phid => $audit) {
      if (in_array($audit->getAuditStatus(), $statuses)) {
        $commit_dates[$commit_phid] = $this->commits[$commit_phid]->getEpoch();
      }
    }

    return $commit_dates;
  }

  private function getFixCommits(PhabricatorObjectHandle $commit_handle) {
    $inverse_mentions =
      idx($this->inverseMentionMap, $commit_handle->getPHID());

    if (!$inverse_mentions) {
     return array();
    }

    $fixed_in = array();
    $expected_fix_commit_prefix = 'fixes: '.$commit_handle->getName();

    foreach ($inverse_mentions as $inverse_mention_phid) {
      $fix_commit = $this->inverseMentionCommits[$inverse_mention_phid];

      if ($fix_commit->getCommitType() != PhabricatorCommitType::COMMIT_FIX) {
        continue;
      }

      list ($fix_commit_prefix,) = $fix_commit->parseCommitMessage();

      if ($fix_commit_prefix == $expected_fix_commit_prefix) {
        $fixed_in[] = $fix_commit->getRepository()->formatCommitName(
          $fix_commit->getCommitIdentifier());
      }
    }

    return $fixed_in;
  }

  private function prepareInverseMentions() {
    if (!$this->transactions) {
      return;
    }

    $inverse_mentions = array();
    $type_const = PhabricatorRepositoryCommitPHIDType::TYPECONST;
    $edge_const = PhabricatorObjectMentionedByObjectEdgeType::EDGECONST;

    foreach ($this->transactions as $xaction) {
      if ($xaction->getTransactionType() != PhabricatorTransactions::TYPE_EDGE
        || head($xaction->getMetadata('edge:type')) !== $edge_const
      ) {
        continue;
      }

      $commit_phid = $xaction->getObjectPHID();
      if (!idx($inverse_mentions, $commit_phid)) {
        $inverse_mentions[$commit_phid] = array();
      }

      foreach ($xaction->getNewValue() as $edge_data) {
        $mentioned_by_phid = $edge_data['dst'];

        if (phid_get_type($mentioned_by_phid) === $type_const) {
          $inverse_mentions[$commit_phid][$mentioned_by_phid] = true;
        };
      }
    }

    $this->inverseMentionMap = array_map('array_keys', $inverse_mentions);
    $this->queryInverseMentionCommits();
  }

  private function queryInverseMentionCommits() {
    if (!$this->inverseMentionMap) {
      return;
    }

    $phids = call_user_func_array('array_merge', $this->inverseMentionMap);

    if (!$phids) {
      return;
    }

    $commits = id(new DiffusionCommitQuery())
      ->setViewer($this->getUser())
      ->withPHIDs($phids)
      ->execute();

    $this->inverseMentionCommits = mpull($commits, null, 'getPHID');
  }

  private function prepareTransactions() {
    $commit_phids = array_keys($this->getCommits());

    if (!$commit_phids) {
      $commit_phids = array(-1);
    }

    $this->transactions = id(new PhabricatorAuditTransactionQuery())
      ->setViewer($this->getUser())
      ->withObjectPHIDs($commit_phids)
      ->needHandles(false)
      ->withTransactionTypes(array(
        // Only commits, that needs audit have these.
        PhabricatorAuditActionConstants::ADD_AUDITORS,
        PhabricatorAuditActionConstants::ACTION,

        // Any commit can have these.
        PhabricatorTransactions::TYPE_EDGE,
      ))
      ->execute();
  }
}
