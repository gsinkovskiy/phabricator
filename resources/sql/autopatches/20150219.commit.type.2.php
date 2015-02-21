<?php

$table = new PhabricatorRepositoryCommit();
$conn_w = $table->establishConnection('w');

echo "Populating type of commit and fixes commit fields...\n";

$repositories = id(new PhabricatorRepositoryQuery())
  ->setViewer(PhabricatorUser::getOmnipotentUser())
  ->execute();
$repositories = array_flip(mpull($repositories, 'getCallsign'));

foreach (new LiskMigrationIterator($table) as $commit) {
  $regex = '/^\[fixes: r([A-Z]+)([^\]]*)\] (.*)$/s';
  if (!preg_match($regex, $commit->getSummary(), $regs)) {
    continue;
  }

  $repository_callsign = $regs[1];

  // Trailing spaces after commit, but allow it during migration.
  $commit_identifier = trim($regs[2]);
  echo 'Commit r'.$commit->getCommitIdentifier();
  echo ' fixes r'.$commit_identifier.' commit ... ';

  // Several commits listed, don't allow it.
  if (strpos($commit_identifier, ',') === false) {
    $commit_data = queryfx_one(
      $conn_w,
      'SELECT id FROM %T WHERE repositoryID = %s AND commitIdentifier = %d',
      $table->getTableName(),
      $repositories[$repository_callsign],
      $commit_identifier);
  }

  if ($commit_data) {
    $commit->setCommitType(PhabricatorCommitType::COMMIT_FIX);
    $commit->update();
    echo 'YES'.PHP_EOL;
  } else {
    echo 'NO'.PHP_EOL;
  }
}

echo "Done.\n";
