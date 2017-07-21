<?php

namespace Deployer;

use SourceBroker\DeployerExtendedDatabase\Utility\ArrayUtility;
use SourceBroker\DeployerExtendedDatabase\Utility\InstanceUtility;

// deployer settings
set('default_stage', function () {
    return InstanceUtility::getCurrentInstance();
});

// Return what deployer to download on source server when we use phar deployer.
set('db_deployer_version', 4);

// Return current instance name. Based on that scripts knows from which server() takes the data to database.
set('db_instance', function () {
    return InstanceUtility::getCurrentInstance();
});

// Returns current server settings.
set('db_current_server', function () {
    try {
        $currentServer = Deployer::get()->environments[get('db_instance')];
    } catch (\RuntimeException $e) {
        $servers = '';
        $i = 1;
        foreach (Deployer::get()->environments as $key => $server) {
            $servers .= "\n" . $i++ . '. ' . $key;
        }
        throw new \RuntimeException("Name of instance \"" . get('db_instance') . "\" is not on the server list:" . $servers
            . "\nPlease check case sensitive. [Error code: 1458412947]");
    }
    return $currentServer;
});

// Contains "db_databases" merged for direct use.
set('db_databases_merged', function () {
    $dbConfigsMerged = [];
    foreach (get('db_databases') as $dbIdentifier => $dbConfigs) {
        $dbConfigsMerged[$dbIdentifier] = [];
        foreach ($dbConfigs as $dbConfig) {
            if (is_array($dbConfig)) {
                $dbConfigsMerged[$dbIdentifier]
                    = ArrayUtility::arrayMergeRecursiveDistinct($dbConfigsMerged[$dbIdentifier], $dbConfig);
                continue;
            }
            if (is_object($dbConfig) && ($dbConfig instanceof \Closure)) {
                $mergeArray = call_user_func($dbConfig);
                $dbConfigsMerged[$dbIdentifier] = ArrayUtility::arrayMergeRecursiveDistinct($dbConfigsMerged[$dbIdentifier],
                    $mergeArray);
            }
            if (is_string($dbConfig)) {
                if (file_exists($dbConfig)) {
                    $mergeArray = include($dbConfig);
                    $dbConfigsMerged[$dbIdentifier] = ArrayUtility::arrayMergeRecursiveDistinct($dbConfigsMerged[$dbIdentifier],
                        $mergeArray);
                } else {
                    throw new \RuntimeException('The config file does not exists: ' . $dbConfig);
                }
            }
        }
    }
    return $dbConfigsMerged;
});

set('db_storage_path_current', function () {
    if (get('db_current_server')->get('db_storage_path_relative', false) == false) {
        $dbStoragePathCurrent = get('db_current_server')->get('deploy_path') . '/.dep/database/dumps';
    } else {
        $dbStoragePathCurrent = get('db_current_server')->get('deploy_path') . '/' . get('db_current_server')->get('db_storage_path_relative');
    }
    runLocally("[ -d " . $dbStoragePathCurrent . " ] || mkdir -p " . $dbStoragePathCurrent);
    return $dbStoragePathCurrent;
});

set('db_storage_path', function () {
    if (get('db_storage_path_relative', false) == false) {
        $dbStoragePath = get('deploy_path') . '/.dep/database/dumps';
    } else {
        $dbStoragePath = get('deploy_path') . '/' . get('db_storage_path_relative');
    }
    run("[ -d " . $dbStoragePath . " ] || mkdir -p " . $dbStoragePath);
    return $dbStoragePath;
});

set('bin/deployer', function () {
    set('active_path', get('deploy_path') . '/' . (test('[ -L {{deploy_path}}/release ]') ? 'release' : 'current'));
    //check if there is composer based deployer
    if (test('[ -e \'{{active_path}}/vendor/bin/dep\' ]')) {
        $deployerBin = parse('{{active_path}}/vendor/bin/dep');
    } else {
        $deployerMinimumSizeInBytesForDownloadCheck = 100000;
        // Figure out what version is expected to be used. "db_deployer_version" can be either integer "4"
        // or full verison "4.3.0". There is no support for "4.3".
        // TODO: Find the most recent versions automaticaly
        $deployerVersionRequestedByUser = trim(get('db_deployer_version'));
        if (strpos($deployerVersionRequestedByUser, '.') === false) {
            switch (($deployerVersionRequestedByUser)) {
                case 5:
                    $deployerVersionToUse = '5.0.3';
                    break;
                case 4:
                    $deployerVersionToUse = '4.3.0';
                    break;
                case 3:
                    $deployerVersionToUse = '3.3.0';
                    break;
                case 2:
                    $deployerVersionToUse = '2.0.5';
                    break;
                default:
                    throw new \RuntimeException('You requested deployer version "' . $deployerVersionRequestedByUser . '" 
                but we are not able to determine what exact version is supposed to be chosen. 
                Please set "db_deployer_version" to full semantic versioning like "' . $deployerVersionRequestedByUser . '.0.1"');
            }
        } else {
            if (count(explode('.', $deployerVersionRequestedByUser)) === 3) {
                $deployerVersionToUse = $deployerVersionRequestedByUser;
            } else {
                throw new \RuntimeException('Deployer version must be set to just "major" like "4" which means give me most '
                    . 'fresh version for deployer 4 or "major.minor.path" like "4.3.0". The "' . $deployerVersionRequestedByUser
                    . '" is not supported.');
            }
        }
        $deployerFilename = 'deployer-' . $deployerVersionToUse . '.phar';
        $deployerFilenameFullPath = get('deploy_path') . '/shared/' . $deployerFilename;
        if (test('[ -f ' . $deployerFilenameFullPath . ' ]')) {
            $downloadedFileSizeInBytes = trim(run('wc -c  < {{deploy_path}}/shared/' . $deployerFilename)->toString());
            if ($downloadedFileSizeInBytes < $deployerMinimumSizeInBytesForDownloadCheck) {
                writeln(parse('Removing {{deploy_path}}/shared/' . $deployerFilename . ' because the file size when last '
                    . 'downloaded was ' . $downloadedFileSizeInBytes . ' bytes so downloads was probably not sucessful.'));
                run('rm -f {{deploy_path}}/shared/' . $deployerFilename);
            }
        }
        //If we do not have yet this version fo deployer in shared then download it
        if (!test('[ -f ' . $deployerFilenameFullPath . ' ]')) {
            $deployerDownloadLink = 'https://deployer.org/releases/v' . $deployerVersionToUse . '/deployer.phar';
            run('cd {{deploy_path}}/shared && curl -o ' . $deployerFilename . ' -L ' . $deployerDownloadLink . ' && chmod 775 ' . $deployerFilename);
            //Simplified checker if deployer has been downloaded or not. Just check size of file which should be at least 200kB.
            $downloadedFileSizeInBytes = trim(run('wc -c  < ' . $deployerFilenameFullPath)->toString());
            if ($downloadedFileSizeInBytes > $deployerMinimumSizeInBytesForDownloadCheck) {
                run("cd {{deploy_path}}/shared && chmod 775 " . $deployerFilename);
            } else {
                throw new \RuntimeException(parse('Downloaded deployer has size ' . $downloadedFileSizeInBytes . ' bytes. It seems'
                    . ' like the download was unsucessfull. The file downloaded was: "' . $deployerDownloadLink . '".'
                    . 'Please check if this link return file. The downloaded content was stored in ' . $deployerFilenameFullPath));
            }
        }
        //Rebuild symlink of $deployerFilename to "{{active_path}}/deployer.phar"
        run('rm -f {{active_path}}/deployer.phar && cd {{active_path}} && {{bin/symlink}} ' . $deployerFilenameFullPath . ' {{active_path}}/deployer.phar');
        if (test('[ -f {{active_path}}/deployer.phar ]')) {
            $deployerBin = parse('{{active_path}}/deployer.phar');
        } else {
            throw new \RuntimeException(parse('Can not create symlink from ' . $deployerFilenameFullPath . ' to {{active_path}}/deployer.phar'));
        }
    }
    return $deployerBin;
});

// Local call of deployer can be not standard. For example someone could have "dep3" and "dep4" symlinks and call
// "dep3 deploy live". He could expect then that if we will use deployer call inside task we will use then "dep3" and not "dep"
// so we store actual way of calling deployer into "local/bin/deployer" var to use it whenever we call local deployer again in tasks.
set('local/bin/deployer', function () {
    if ($_SERVER['_'] == $_SERVER['PHP_SELF']) {
        return $_SERVER['_'];
    } else {
        return $_SERVER['_'] . ' ' . $_SERVER['PHP_SELF'];
    }
});

set('bin/mysqldump', function () {
    if (runLocally('if hash mysqldump 2>/dev/null; then echo \'true\'; fi')->toBool()) {
        return 'mysqldump';
    } else {
        throw new \RuntimeException('The mysqldump path on server "' . get('server')['name'] . '" is unknown. You can set it in env var "bin/mysqldump" . [Error code: 1458412747]');
    }
});

set('bin/mysql', function () {
    if (runLocally('if hash mysql 2>/dev/null; then echo \'true\'; fi')->toBool()) {
        return 'mysql';
    } else {
        throw new \RuntimeException('The mysql path on server "' . get('server')['name'] . '" is unknown. You can set it in env var "bin/mysql" . [Error code: 1458412748]');
    }
});
