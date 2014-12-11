<?php

/**
 * Execute and parse a low-level SVN refs query using `svn ls`.
 */
final class DiffusionLowLevelSVNRefQuery
  extends DiffusionLowLevelQuery {

  private $contains;
  private $isTag = false;

  public function withContainsCommit($commit) {
    $this->contains = $commit;
    return $this;
  }

  public function withIsTag($is_tag) {
    $this->isTag = $is_tag;
    return $this;
  }

  protected function executeQuery() {
    $paths = array();
    $top_paths = array();

    foreach ($this->getPaths() as $path) {
      $regs = null;
      if (preg_match('@^(.*)/\*$@', $path, $regs)) {
        $paths = array_merge_recursive($paths, $this->querySubPaths($regs[1]));
      } else {
        if (!$top_paths) {
          $top_paths = $this->querySubPaths('');
        }

        if (idx($top_paths, $path)) {
          $paths[$path] = $top_paths[$path];
        }
      }
    }

    $refs = array();
    $path_filter = $this->getPathFilter();

    foreach ($paths as $path => $path_info) {
      if (!$path_filter
        || ($path_filter && preg_match('#^'.$path.'(/|$)#', $path_filter))
      ) {
        $ref = id(new DiffusionRepositoryRef())
          ->setShortName($path)
          ->setCommitIdentifier($path_info['revision'])
          ->setRawFields(array(
            'author' => $path_info['author'],
            'epoch' => $path_info['epoch'],));

        $refs[$path] = $ref;
      }
    }

    return $refs;
  }

  private function getPaths() {
    $repository = $this->getRepository();

    if ($this->isTag) {
      return array($repository->getSubversionTagsFolder().'/*');
    }

    return array(
      $repository->getSubversionTrunkFolder(),
      $repository->getSubversionBranchesFolder().'/*',);
  }

  private function querySubPaths($path) {
    $paths = array();
    $repository = $this->getRepository();
    $subpath = $repository->getDetail('svn-subpath');

    list($xml) = $repository->execxRemoteCommand(
      'ls %s --xml',
      $repository->getSubversionPathURI($subpath.$path));
    $ls = new SimpleXMLElement($xml);

    if ($path) {
      $path .= '/';
    }

    foreach ($ls->list->entry as $entry) {
      $paths[$path.$entry->name] = array(
        'revision' => (int)$entry->commit['revision'],
        'author' => (string)$entry->commit->author,
        'epoch' => strtotime($entry->commit->date),
      );
    }

    return $paths;
  }

  private function getPathFilter() {
    if (!$this->contains) {
      return '';
    }

    $repository = $this->getRepository();
    $subpath = $repository->getDetail('svn-subpath');

    list($xml) = $repository->execxRemoteCommand(
      'log %s --verbose --limit 1 --xml',
      $repository->getSubversionBaseURI($this->contains));

    $log = new SimpleXMLElement($xml);
    $path_filter = ltrim($log->logentry->paths->path, '/');

    if ($subpath) {
      $path_filter = preg_replace('#^'.$subpath.'#', '', $path_filter, 1);
    }

    return $path_filter;
  }

}
