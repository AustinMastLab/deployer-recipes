<?php

/*
 * Deploy the assets built in CI, check the release, and reload the site's Supervisor groups.
 *
 * Usage in a site's deploy.php:
 *
 *     require __DIR__.'/vendor/austinmastlab/deployer-recipes/recipe/ci-artifacts.php';
 *
 *     set('supervisor_groups', ['biospex']);
 *
 * Tasks:
 *
 * - deploy:ci-artifacts      Downloads the "{ci_artifact_name}-{GITHUB_SHA}" artifact that the site's
 *                            GitHub Actions build uploaded, and unpacks it into the release.
 * - deploy:verify-structure  Fails the deploy if a nested "deployment-package" directory is left over.
 * - supervisor:reload        Rereads Supervisor's configs and applies changes to this site's groups only.
 */

namespace Deployer;

// GitHub repository the artifact belongs to, for example "AustinMastLab/Biospex". Read from 'repository'.
set('ci_artifact_repository', function () {
    if (! preg_match('#github\.com[/:]([^/]+/[^/]+?)(?:\.git)?/?$#', (string) get('repository'), $matches)) {
        throw new \RuntimeException("Can't read the GitHub repository from 'repository'. Set 'ci_artifact_repository', for example set('ci_artifact_repository', 'AustinMastLab/Biospex').");
    }

    return $matches[1];
});

// The artifact is "{ci_artifact_name}-{commit SHA}". Defaults to the repository name in lower case.
set('ci_artifact_name', function () {
    return strtolower(basename(get('ci_artifact_repository')));
});

// Outside GitHub Actions there is no artifact. By default the deploy fails, so a manual deploy can't
// publish a release without its built assets. Set to true to skip the download instead.
set('ci_artifacts_optional', false);

// Optional umask for the commands that write the artifact into the release, for example '002'.
set('deploy_umask', '');

// Supervisor groups this site owns. Required, so a deploy never applies another site's pending changes.
// A program outside any group is its own group, named after the program.
set('supervisor_groups', function () {
    throw new \RuntimeException("Set 'supervisor_groups' in deploy.php, for example set('supervisor_groups', ['biospex']).");
});

/**
 * Read an environment variable set by the GitHub Actions workflow, or '' when it isn't set.
 */
function ciArtifactsEnv(string $name): string
{
    $value = $_ENV[$name] ?? getenv($name);

    return is_string($value) ? $value : '';
}

/**
 * Prefix a command with the 'deploy_umask' setting, if one is set.
 */
function ciArtifactsUmask(string $command): string
{
    $umask = (string) get('deploy_umask');

    return $umask === '' ? $command : 'umask '.escapeshellarg($umask)." && {$command}";
}

desc('Download the assets built in CI into the release');
task('deploy:ci-artifacts', function () {
    if (ciArtifactsEnv('GITHUB_ACTIONS') !== 'true') {
        if (get('ci_artifacts_optional')) {
            writeln('⚠️  Skipping deploy:ci-artifacts: not running in GitHub Actions, so there is no artifact.');

            return;
        }

        throw new \RuntimeException('deploy:ci-artifacts needs the artifact from a GitHub Actions build. Deploy from the workflow, or set ci_artifacts_optional to deploy without it.');
    }

    $token = ciArtifactsEnv('GITHUB_TOKEN');
    $sha = ciArtifactsEnv('GITHUB_SHA');

    if ($token === '' || $sha === '') {
        throw new \RuntimeException('GITHUB_TOKEN and GITHUB_SHA must be set by the workflow for deploy:ci-artifacts.');
    }

    $repository = get('ci_artifact_repository');
    $artifactName = get('ci_artifact_name')."-{$sha}";
    writeln("Downloading CI artifact {$artifactName} from {$repository}");

    $response = runLocally("curl -fsS -H 'Authorization: Bearer {$token}' -H 'Accept: application/vnd.github+json' 'https://api.github.com/repos/{$repository}/actions/artifacts?name={$artifactName}&per_page=1'");
    $artifacts = json_decode($response, true);

    if (empty($artifacts['artifacts'])) {
        throw new \RuntimeException("No CI artifact named {$artifactName} in {$repository}.");
    }

    $downloadUrl = $artifacts['artifacts'][0]['archive_download_url'];
    cd('{{release_or_current_path}}');

    run(ciArtifactsUmask("curl -fsSL -H 'Authorization: Bearer {$token}' -H 'Accept: application/vnd.github+json' '{$downloadUrl}' -o artifact.zip"));
    run(ciArtifactsUmask('unzip -o -q artifact.zip'));

    // The build uploads a "deployment-package" directory; move the innermost one's contents to the release root.
    $innermost = trim(run('find . -type d -name "deployment-package" | sort -r | head -1'));

    if ($innermost !== '') {
        run(ciArtifactsUmask("rsync -a '{$innermost}/' ./"));
        run('find . -type d -name "deployment-package" -prune -exec rm -rf {} +');
    }

    run('rm -f artifact.zip');
    writeln('✅ CI artifact deployed');
});

desc('Fail the deploy if a nested deployment-package directory is left in the release');
task('deploy:verify-structure', function () {
    $nested = (int) trim(run('find {{release_path}} -type d -name "deployment-package" | wc -l'));

    if ($nested > 0) {
        throw new \RuntimeException("Found {$nested} deployment-package directories in the release. Check the CI artifact.");
    }

    writeln('✅ Release structure verified');
});

desc("Reread Supervisor's configs and apply changes to this site's groups");
task('supervisor:reload', function () {
    $groups = get('supervisor_groups');

    if (! is_array($groups) || $groups === []) {
        throw new \RuntimeException("'supervisor_groups' must list at least one Supervisor group.");
    }

    run('sudo supervisorctl reread');
    run('sudo supervisorctl update '.implode(' ', array_map('escapeshellarg', $groups)));
});
