#!/usr/bin/env php
<?php
require(__DIR__.'/../vendor/autoload.php');

chdir(__DIR__.'/..');

out("<!white>Test runner for multiple environments<!>\n".
            "=====================================\n\n");

$extensions = [
    null,           // test the default environment
    'ev',           // test with this extension enabled
];

$libraries = [
    [null],
    ['amphp/amp', '<3.0', 'composer'],
    ['react/event-loop', '<2.0', 'composer'],
];



$phpquery = trim(shell_exec('which phpquery'));
$phpenmod = trim(shell_exec('which phpenmod'));
$phpdismod = trim(shell_exec('which phpdismod'));

if ($phpquery == '' || $phpenmod == '' || $phpdismod == '') {
    $phpquery = $phpenmod = $phpdismod = null;
    $extensions = null;
    echo " - unable to test with extensions because the phpquery command was not found\n";
} elseif (!empty($extensions)) {
    $extensionState = [];
    // were any extensions enabled?
    foreach ($extensions as $ext) {
        if ($ext === null) {
            continue;
        }
        $state = phpquery($ext);
        if ($state === 1) {
            echo " - extension '$ext' is not installed\n";
            unset($extensions[$k]);
            continue;
        }
        $extensionState[$ext] = $state;
        if ($state === 0) {
            phpdismod($ext);
            register_shutdown_function(function() use ($ext) {
                phpenmod($ext);
            });
        }
    }
}

$successes = [];
$errors = [];

if ($phpquery && !empty($extensions)) {
    foreach ($extensions as $k => $ext) {

        $cleanup = [];
        if ($ext === null) {
            $extText = "no extension";
            out("<!blue>Testing without any of the extensions enabled<!>\n\n");
        } else {
            out("<!blue>Testing with the <!underline>$ext<!> extension enabled<!>\n\n");
            $extText = "the $ext extension";
            phpenmod($ext);
            $cleanup[] = function() use ($ext) {
                phpdismod($ext);
            };
        }

        foreach ($libraries as $lib) {
            if ($lib[0] === null) {
                out("<!teal> * Testing with <!underline>no extra composer packages<!>\n\n");
                $libText = "no extra composer packages";
            } else {
                out("<!teal> * Testing with <!underline>".$lib[0]."<!> installed<!>\n\n");
                $libText = "the composer package ".$lib[0]." version ".$lib[1]." installed";
                composer_add($lib[0], $lib[1]);
            }

            $result = run_tests();

            if ($result) {
                $successes[] = $extText." and ".$libText;
            } else {
                $errors[] = $extText." and ".$libText;
            }

            if ($lib[0] !== null) {
                composer_remove($lib[0]);
            }
        }
        foreach ($cleanup as $cb) {
            $cb();
        }
        out("\n");
    }
} else {
    foreach ($libraries as $lib) {
        if ($lib[0] === null) {
            // vanilla
            if (!run_tests()) {
                $errors[] = "Default extensions with no extra composer packages";
            } else {
                $successes[] = "Default extensions with no extra composer packages";
            }
        } else {
            composer_add($lib[0], $lib[1]);
            if (!run_tests($lib[0], $lib[1])) {
                $errors[] = "Default extensions with ".$lib[0]." ".$lib[1]." installed";
            } else {
                $successes[] = "Default extensions with ".$lib[0]." ".$lib[1]." installed";
            }
            composer_remove($lib[0]);
        }
    }
}

if (count($errors) > 0) {
    out(" ❌ <!underline>".count($errors)."<!> tests failed\n\n");
    foreach ($errors as $error) {
        out(" - $error\n");
    }
    out("\n");
    exit(1);
} else {
    out(" ✅ All tests were successful\n\n");
    exit(0);
}

/*

if (empty($errors)) {
    out("<!green>All tests succeeded<!>\n\n");
    exit(0);
} else {
    out("<!red>Tests failed with the following libraries installed:<!>\n\n");
    foreach ($errors as $lib) {
        out(" * <!white>".$lib[0]." ".$lib[1]."<!>\n");
    }
    out("\n");
    exit(1);
}
*/
function run_tests() {
    passthru("cd ".escapeshellarg(dirname(__DIR__))."; ./vendor/bin/charm-testing --no-linting --no-git --summary 2> /dev/null", $result_code);
    return $result_code === 0;
}

function composer_add(string $library, string $versionConstraint) {
    passthru("cd ".escapeshellarg(dirname(__DIR__))."; composer -n require ".escapeshellarg($library)." ".escapeshellarg($versionConstraint)." 2> /dev/null > /dev/null");
}

function composer_remove(string $library) {
    passthru("cd ".escapeshellarg(dirname(__DIR__))."; composer -n remove ".escapeshellarg($library)." 2> /dev/null > /dev/null");
}

function out(string $t) {
    $term = new Charm\Terminal(STDERR);
    $term->write($t);
}

function phpquery(string $module) {
    global $phpquery;
    exec("$phpquery -v ".PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION." -s ".strtolower(PHP_SAPI)." -m ".escapeshellarg($module)." 2> /dev/null > /dev/null", $void, $result);
    return $result;
}

function phpdismod(string $module) {
    global $phpdismod;
    exec("$phpdismod -v ".PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION." -s ".strtolower(PHP_SAPI)." ".escapeshellarg($module)." 2> /dev/null > /dev/null", $void, $result);
    return $result;
}

function phpenmod(string $module) {
    global $phpenmod;
    exec("$phpenmod -v ".PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION." -s ".strtolower(PHP_SAPI)." ".escapeshellarg($module)." 2> /dev/null > /dev/null", $void, $result);
    return $result;
}
