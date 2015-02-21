ALTER TABLE {$NAMESPACE}_repository.repository_commit
  ADD `commitType` int(10) unsigned NOT NULL AFTER `auditStatus`;
