<?php

final class DifferentialDoorkeeperRevisionFeedStoryPublisher
  extends DoorkeeperFeedStoryPublisher {

  public function canPublishStory(PhabricatorFeedStory $story, $object) {
    return ($object instanceof DifferentialRevision);
  }

  public function isStoryAboutObjectCreation($object) {
    $story = $this->getFeedStory();
    $xaction = $story->getPrimaryTransaction();

    switch ($xaction->getTransactionType()) {
      case DifferentialTransaction::TYPE_ACTION:
        return $xaction->getNewValue() == DifferentialAction::ACTION_CREATE;
    		break;

      case PhabricatorTransactions::TYPE_CUSTOMFIELD:
        $custom_field_name = $xaction->getMetadataValue('customfield:key');
        if ($custom_field_name == 'differential:title' && !strlen($xaction->getOldValue())) {
          return true;
        }
        break;
    }

    return false;
  }

  public function isStoryAboutObjectClosure($object) {
    $story = $this->getFeedStory();
    $xaction = $story->getPrimaryTransaction();

    if ($xaction->getTransactionType() == DifferentialTransaction::TYPE_ACTION) {
      $close_statuses = array(
        DifferentialAction::ACTION_CLOSE, DifferentialAction::ACTION_ABANDON);

      return in_array($xaction->getNewValue(), $close_statuses);
    }

    return false;
  }

  public function isStoryAboutObjectReview($object) {
    $status = $object->getStatus();
    $needs_review_status = ArcanistDifferentialRevisionStatus::NEEDS_REVIEW;
    if ($status != $needs_review_status) {
      return false;
    }

    $story = $this->getFeedStory();
    $edge_reviewer = DifferentialRevisionHasReviewerEdgeType::EDGECONST;

    // When creating revision & adding reviewer,
    // then "reviewer added" transaction isn't primary.
    foreach ($story->getValue('transactionPHIDs') as $xaction_phid) {
      $xaction = $story->getObject($xaction_phid);

      switch ($xaction->getTransactionType()) {
        // Adding/removing reviewers from revision.
        case PhabricatorTransactions::TYPE_EDGE:
          if ($xaction->getMetadataValue('edge:type') == $edge_reviewer) {
            return count($object->getReviewers()) > 0;
          }
      		break;

        // Action, that results in status change to "Needs Review".
        case DifferentialTransaction::TYPE_ACTION:
          $review_statuses = array(
            DifferentialAction::ACTION_RECLAIM,
            DifferentialAction::ACTION_REOPEN,
            DifferentialAction::ACTION_REQUEST,
            DifferentialAction::ACTION_ADDREVIEWERS,
          );

          if (in_array($xaction->getNewValue(), $review_statuses)) {
            return count($object->getReviewers()) > 0;
          }
          break;

        // Direct status change to "Needs Review".
        case DifferentialTransaction::TYPE_STATUS:
          if ($xaction->getNewValue() == $needs_review_status) {
            return count($object->getReviewers()) > 0;
          }
          break;

        // Revision diff updated.
        case DifferentialTransaction::TYPE_UPDATE:
          return count($object->getReviewers()) > 0;
          break;
      }
    }

    return false;
  }

  public function isStoryAboutObjectAccept($object) {
    $story = $this->getFeedStory();
    $xaction = $story->getPrimaryTransaction();

    if ($xaction->getTransactionType() == DifferentialTransaction::TYPE_ACTION) {
      return $xaction->getNewValue() == DifferentialAction::ACTION_ACCEPT;
    }

    return false;
  }

  public function isStoryAboutObjectReject($object) {
    $story = $this->getFeedStory();
    $xaction = $story->getPrimaryTransaction();

    if ($xaction->getTransactionType() == DifferentialTransaction::TYPE_ACTION) {
      return $xaction->getNewValue() == DifferentialAction::ACTION_REJECT;
    }

    return false;
  }

  public function willPublishStory($object) {
    return id(new DifferentialRevisionQuery())
      ->setViewer($this->getViewer())
      ->withIDs(array($object->getID()))
      ->needRelationships(true)
      ->executeOne();
  }

  public function getOwnerPHID($object) {
    return $object->getAuthorPHID();
  }

  public function getActiveUserPHIDs($object) {
    $status = $object->getStatus();
    if ($status == ArcanistDifferentialRevisionStatus::NEEDS_REVIEW) {
      return $object->getReviewers();
    } else {
      return array();
    }
  }

  public function getPassiveUserPHIDs($object) {
    $status = $object->getStatus();
    if ($status == ArcanistDifferentialRevisionStatus::NEEDS_REVIEW) {
      return array();
    } else {
      return $object->getReviewers();
    }
  }

  public function getCCUserPHIDs($object) {
    return $object->getCCPHIDs();
  }

  public function getObjectTitle($object) {
    $id = $object->getID();

    $title = $object->getTitle();

    return "D{$id}: {$title}";
  }

  public function getObjectURI($object) {
    return PhabricatorEnv::getProductionURI('/D'.$object->getID());
  }

  public function getObjectDescription($object) {
    return $object->getSummary();
  }

  public function isObjectClosed($object) {
    return $object->isClosed();
  }

  public function getResponsibilityTitle($object) {
    $prefix = $this->getTitlePrefix($object);
    return pht('%s Review Request', $prefix);
  }

  private function getTitlePrefix(DifferentialRevision $revision) {
    $prefix_key = 'metamta.differential.subject-prefix';
    return PhabricatorEnv::getEnvConfig($prefix_key);
  }

}
