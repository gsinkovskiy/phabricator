<?php

final class PhabricatorCommentEditType extends PhabricatorEditType {

  protected function newConduitParameterType() {
    return new ConduitStringParameterType();
  }

  public function generateTransactions(
    PhabricatorApplicationTransaction $template,
    array $spec) {

    $comment = $template->getApplicationTransactionCommentObject()
      ->setContent(idx($spec, 'value'));

    $xaction = $this->newTransaction($template)
      ->attachComment($comment);

    if (isset($spec['silent'])) {
      $xaction->setMetadataValue('silent', $spec['silent']);
    }

    return array($xaction);
  }

}
