<?php

namespace Deployer;

use SourceBroker\DeployerExtendedDatabase\Utility\ConsoleUtility;

/*
 * @see https://github.com/sourcebroker/deployer-extended-database#db-decompress
 */
task('db:decompress', function () {
    $dumpCode = (new ConsoleUtility())->optionRequired('dumpcode', input());
    if (get('db_instance') == get('server')['name']) {
        $markersArray = [];
        $markersArray['{{databaseStorageAbsolutePath}}'] = get('db_current_server')->get('db_storage_path_current');
        $markersArray['{{dumpcode}}'] = $dumpCode;
        if (get('db_decompress_command', false) !== false) {
            foreach (get('db_decompress_command') as $dbProcessCommand) {
                runLocally(str_replace(
                    array_keys($markersArray),
                    $markersArray,
                    $dbProcessCommand
                ), 0);
            }
        }
    } else {
        $verbosity = (new ConsoleUtility())->getVerbosityAsParameter(output());
        $activePath = get('deploy_path') . '/' . (test('[ -L {{deploy_path}}/release ]') ? 'release' : 'current');
        run('cd ' . $activePath . ' && {{bin/php}} {{bin/deployer}} db:decompress --dumpcode=' . $dumpCode . ' ' . $verbosity);
    }
})->desc('Compress dumps with given dumpcode.');
