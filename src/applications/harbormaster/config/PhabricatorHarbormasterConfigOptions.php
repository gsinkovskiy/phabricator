<?php

final class PhabricatorHarbormasterConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Harbormaster');
  }

  public function getDescription() {
    return pht('Configure Harbormaster build engine.');
  }

  public function getIcon() {
    return 'fa-ship';
  }

  public function getGroup() {
    return 'apps';
  }

  public function getOptions() {
    return array(
      $this->newOption('jenkins.host', 'string', null)
        ->setDescription(pht('Jenkins installation hostname.')),
      $this->newOption('jenkins.user-id', 'string', null)
        ->setDescription(pht('Username for accessing Jenkins.')),
      $this->newOption('jenkins.api-token', 'string', null)
        ->setHidden(true)
        ->setDescription(pht('API token for accessing Jenkins.')),
      $this->newOption('jenkins.repository-uuid', 'string', null)
          ->setDescription(pht('Repository UUID to notify about new commits.')),
    );
  }

}
