<?php

final class DiffusionSvnRequest extends DiffusionRequest {

  public function supportsBranches() {
    return $this->repository->supportsBranches();
  }

  protected function isStableCommit($symbol) {
    return preg_match('/^[1-9]\d*\z/', $symbol);
  }

  protected function didInitialize() {
    if ($this->path === null) {
      $subpath = $this->repository->getDetail('svn-subpath');
      if ($subpath) {
        $this->path = $subpath;
      }
    }
  }

  public function getBranch() {
    if (!$this->repository || !$this->repository->supportsBranches()) {
      return $this->branch;
    }

    if ($this->branch) {
      return $this->branch;
    }

    if ($this->repository) {
      return $this->repository->getDefaultBranch();
    }

    throw new Exception('Unable to determine branch!');
  }

}
