<?php

final class PhabricatorPhurlURLSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function getResultTypeDescription() {
    return pht('Shortened URLs');
  }

  public function getApplicationClassName() {
    return 'PhabricatorPhurlApplication';
  }

  public function newQuery() {
    return new PhabricatorPhurlURLQuery();
  }

  protected function shouldShowOrderField() {
    return true;
  }

  protected function buildCustomSearchFields() {
    return array(
      id(new PhabricatorSearchDatasourceField())
        ->setLabel(pht('Created By'))
        ->setKey('authorPHIDs')
        ->setDatasource(new PhabricatorPeopleUserFunctionDatasource()),
    );
  }

  protected function buildQueryFromParameters(array $map) {
    $query = $this->newQuery();

    if ($map['authorPHIDs']) {
      $query->withAuthorPHIDs($map['authorPHIDs']);
    }

    return $query;
  }

  protected function getURI($path) {
    return '/phurl/'.$path;
  }

  protected function getBuiltinQueryNames() {
    $names = array(
      'authored' => pht('Authored'),
      'all' => pht('All URLs'),
    );

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {
    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);
    $viewer = $this->requireViewer();

    switch ($query_key) {
      case 'authored':
        return $query->setParameter('authorPHIDs', array($viewer->getPHID()));
      case 'all':
        return $query;
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

  protected function renderResultList(
    array $urls,
    PhabricatorSavedQuery $query,
    array $handles) {

    assert_instances_of($urls, 'PhabricatorPhurlURL');
    $viewer = $this->requireViewer();
    $list = new PHUIObjectItemListView();
    $handles = $viewer->loadHandles(mpull($urls, 'getAuthorPHID'));

    foreach ($urls as $url) {
      $item = id(new PHUIObjectItemView())
        ->setUser($viewer)
        ->setObject($url)
        ->setHeader($viewer->renderHandle($url->getPHID()));

      $list->addItem($item);
    }

    $result = new PhabricatorApplicationSearchResultView();
    $result->setObjectList($list);
    $result->setNoDataString(pht('No URLs found.'));

    return $result;
  }
}
