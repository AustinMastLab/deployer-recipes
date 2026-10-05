<?php

/*
 * Generate the shared .env file from the AWS SSM Parameter Store.
 *
 * Usage in a site's deploy.php:
 *
 *     require __DIR__.'/vendor/austinmastlab/deployer-recipes/recipe/ssm-env.php';
 *
 *     set('ssm_app', 'biospex');
 *
 * and add 'env:ssm' to the deploy task list after 'deploy:prepare'.
 *
 * The task streams bin/generate-env to the server and runs it there, so the server's
 * own IAM role reads the parameters and no secrets pass through the machine running
 * Deployer. Nothing needs to be installed on the server apart from the AWS CLI and jq.
 */

namespace Deployer;

// Parameters are read from /{{ssm_app}}/{{ssm_environment}}.
set('ssm_app', function () {
    throw new \RuntimeException("Set 'ssm_app' in deploy.php, for example set('ssm_app', 'biospex').");
});

// Defaults to the host's "environment" setting (production, development, ...).
set('ssm_environment', function () {
    return get('environment');
});

set('ssm_region', 'us-east-2');

set('ssm_env_file', '{{deploy_path}}/shared/.env');

// Number of timestamped .env backups to keep next to the .env file.
set('ssm_env_backups', 5);

desc('Generate .env from AWS SSM Parameter Store');
task('env:ssm', function () {
    $script = file_get_contents(__DIR__.'/../bin/generate-env');

    $arguments = implode(' ', array_map('escapeshellarg', [
        get('ssm_app'),
        get('ssm_environment'),
        parse('{{ssm_env_file}}'),
        (string) get('ssm_env_backups'),
    ]));

    $output = run(
        'AWS_REGION='.escapeshellarg(get('ssm_region'))." bash -s -- {$arguments} <<'GENERATE_ENV'\n{$script}\nGENERATE_ENV"
    );

    writeln($output);
});
