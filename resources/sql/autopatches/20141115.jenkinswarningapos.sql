UPDATE {$NAMESPACE}_repository.repository_commitproperty
SET `data` = REPLACE(`data`, '&apos;', "'")
WHERE `data` LIKE "%&apos;%";
