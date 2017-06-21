<?php

/**
 * Resolves references into canonical, stable commit identifiers by examining
 * database caches.
 *
 * This is a counterpart to @{class:DiffusionLowLevelResolveRefsQuery}. This
 * query offers fast resolution, but can not resolve everything that the
 * low-level query can.
 *
 * This class can resolve the most common refs (commits, branches, tags) and
 * can do so cheapy (by examining the database, without needing to make calls
 * to the VCS or the service host).
 */
final class DiffusionCachedResolveRefsQuery
  extends DiffusionLowLevelQuery {

  private $refs;
  private $types;

  public function withRefs(array $refs) {
    $this->refs = $refs;
    return $this;
  }

  public function withTypes(array $types) {
    $this->types = $types;
    return $this;
  }

  protected function executeQuery() {
    if (!$this->refs) {
      return array();
    }

    switch ($this->getRepository()->getVersionControlSystem()) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        $result = $this->resolveGitAndMercurialRefs();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        $result = $this->resolveSubversionRefs();
        break;
      default:
        throw new Exception(pht('Unsupported repository type!'));
    }

    if ($this->types !== null) {
      $result = $this->filterRefsByType($result, $this->types);
    }

    return $result;
  }

  /**
   * Resolve refs in Git and Mercurial repositories.
   *
   * We can resolve commit hashes from the commits table, and branch and tag
   * names from the refcursor table.
   */
  private function resolveGitAndMercurialRefs() {
    $repository = $this->getRepository();

    $conn_r = $repository->establishConnection('r');

    $results = array();

    $prefixes = array();
    foreach ($this->refs as $ref) {
      // We require refs to look like hashes and be at least 4 characters
      // long. This is similar to the behavior of git.
      if (preg_match('/^[a-f0-9]{4,}$/', $ref)) {
        $prefixes[] = qsprintf(
          $conn_r,
          '(commitIdentifier LIKE %>)',
          $ref);
      }
    }

    if ($prefixes) {
      $commits = queryfx_all(
        $conn_r,
        'SELECT commitIdentifier FROM %T
          WHERE repositoryID = %s AND %Q',
        id(new PhabricatorRepositoryCommit())->getTableName(),
        $repository->getID(),
        implode(' OR ', $prefixes));

      foreach ($commits as $commit) {
        $hash = $commit['commitIdentifier'];
        foreach ($this->refs as $ref) {
          if (!strncmp($hash, $ref, strlen($ref))) {
            $results[$ref][] = array(
              'type' => 'commit',
              'identifier' => $hash,
            );
          }
        }
      }
    }

    $name_hashes = array();
    foreach ($this->refs as $ref) {
      $name_hashes[PhabricatorHash::digestForIndex($ref)] = $ref;
    }

    $cursors = queryfx_all(
      $conn_r,
      'SELECT refNameHash, refType, commitIdentifier, isClosed FROM %T
        WHERE repositoryPHID = %s AND refNameHash IN (%Ls)',
      id(new PhabricatorRepositoryRefCursor())->getTableName(),
      $repository->getPHID(),
      array_keys($name_hashes));

    foreach ($cursors as $cursor) {
      if (isset($name_hashes[$cursor['refNameHash']])) {
        $results[$name_hashes[$cursor['refNameHash']]][] = array(
          'type' => $cursor['refType'],
          'identifier' => $cursor['commitIdentifier'],
          'closed' => (bool)$cursor['isClosed'],
        );

        // TODO: In Git, we don't store (and thus don't return) the hash
        // of the tag itself. It would be vaguely nice to do this.
      }
    }

    return $results;
  }


  /**
   * Resolve refs in Subversion repositories.
   *
   * We can resolve all numeric identifiers and the keyword `HEAD`.
   */
  private function resolveSubversionRefs() {
    $repository = $this->getRepository();

    if ($repository->supportsBranches()) {
      return $this->resolveGitAndMercurialRefs();
    }

    return array();
  }

}
