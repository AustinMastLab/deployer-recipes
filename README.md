# deployer-recipes

Shared [Deployer](https://deployer.org) recipes and AWS SSM Parameter Store tools for AustinMastLab sites. Each site loads this package with Composer, so every site uses the same versioned deploy logic instead of its own copy.

## Sites using it

| Site | Repository | `ssm_app` | Deploys to |
| --- | --- | --- | --- |
| BIOSPEX | [Biospex](https://github.com/AustinMastLab/Biospex) | `biospex` | production, development |
| Digitization Academy | [DigitizationAcademy](https://github.com/AustinMastLab/DigitizationAcademy) | `digitizationacademy` | production, development |
| WeDigBio reports | [wedigbio-reports](https://github.com/AustinMastLab/wedigbio-reports) | `wedigbio-reports` | production, development |

All three run Deployer 8 and PHP 8.5, and deploy to the same two servers (production `3.142.169.134`, development `3.138.217.206`).

## Contents

| Path | Purpose |
| --- | --- |
| `recipe/ssm-env.php` | Deployer task `env:ssm`: generates the site's shared `.env` from SSM on the server. |
| `recipe/ci-artifacts.php` | Deployer tasks `deploy:ci-artifacts`, `deploy:verify-structure` and `supervisor:reload`. |
| `bin/generate-env` | Builds a `.env` from `/<app>/<environment>` in SSM. `env:ssm` runs it on the server. |
| `bin/push-env-params` | Pushes a local env file to SSM as `SecureString` parameters. |
| `bin/delete-env-param` | Deletes individual parameters under `/<app>/<environment>`, after confirmation. |
| `bin/remove-env-params` | Deletes every parameter under `/<app>/<environment>`, after confirmation. |

## Installing in a site

Requires Deployer 8. The `env:ssm` task sends `generate-env` to the server in a shell heredoc, which Deployer 7's command wrapper breaks; Composer refuses to install this package next to Deployer 7.

The package isn't on Packagist, so add the repository and require it as a development dependency. Deployer runs from the CI runner or your machine, never from the server's production `vendor/`.

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/AustinMastLab/deployer-recipes",
        "no-api": true
    }
],
"require-dev": {
    "austinmastlab/deployer-recipes": "^1.0"
}
```

```bash
composer update austinmastlab/deployer-recipes
```

With `"no-api": true`, Composer clones the repository with git instead of calling the GitHub API, which avoids API rate limits in CI.

## `env:ssm`

In the site's `deploy.php`:

```php
require 'recipe/laravel.php';
require __DIR__.'/vendor/austinmastlab/deployer-recipes/recipe/ssm-env.php';

set('ssm_app', 'biospex');

task('deploy', [
    'deploy:prepare',
    'env:ssm',
    // ...
]);
```

Each host needs an `environment` setting (for example `->set('environment', 'production')`), which picks the SSM path `/<ssm_app>/<environment>`.

| Setting | Default | Meaning |
| --- | --- | --- |
| `ssm_app` | (required) | First part of the SSM path. |
| `ssm_environment` | the host's `environment` | Second part of the SSM path. |
| `ssm_region` | `us-east-2` | AWS region. |
| `ssm_env_file` | `{{deploy_path}}/shared/.env` | File to write. |
| `ssm_env_backups` | `5` | Timestamped backups of the `.env` to keep. |

The task streams `bin/generate-env` to the server over SSH and runs it there. Nothing has to be installed on the server apart from the AWS CLI and `jq`, and the server's own IAM role reads the parameters, so secrets never pass through GitHub or the machine running Deployer.

`generate-env`:

- **Refuses to write an empty file.** If no parameters are found, or the role can't read the path, it fails and leaves the existing `.env` untouched.
- **Writes the file only after every parameter has been read,** and keeps the existing permissions and owner.
- **Strips carriage returns and line feeds from values,** which otherwise break Laravel URLs.
- **Backs up the old `.env` only when the content changes,** and keeps the newest `ssm_env_backups` backups.

**Server permissions.** The server's instance role needs `ssm:GetParametersByPath` on `/<app>/<environment>*` and `kms:Decrypt` through SSM. `ProdEC2DeployPolicy` and `DevEC2DeployPolicy` grant this for all three sites.

**First deploy after switching to this task,** or after a change to the output format: the file is rewritten once with the same values (for example, the header line changes or hand-edited lines are normalized), one backup is made, and older backups are trimmed. Later deploys print `No changes: … already matches SSM (N parameters).`

**Rolling back** with `dep rollback` doesn't run `env:ssm`; it only switches `current`. If you need the previous values, copy the matching `shared/.env.backup.*` over `shared/.env`.

To regenerate a `.env` by hand on a server:

```bash
ssh ubuntu@<server> 'bash -s -- biospex production' < bin/generate-env
```

## CI artifacts and Supervisor (`ci-artifacts.php`)

Every site builds its assets in GitHub Actions, uploads them as an artifact named `<name>-<commit SHA>`, and deploys that instead of building on the server. This recipe provides the tasks for that, plus the Supervisor reload.

In the site's `deploy.php`:

```php
require __DIR__.'/vendor/austinmastlab/deployer-recipes/recipe/ci-artifacts.php';

set('supervisor_groups', ['biospex']);

task('deploy', [
    'deploy:prepare',
    'env:ssm',
    'deploy:vendors',
    'deploy:ci-artifacts',
    // ...
    'deploy:publish',
    'supervisor:reload',
    // ...
    'deploy:verify-structure',
]);
```

| Task | What it does |
| --- | --- |
| `deploy:ci-artifacts` | Finds the `<ci_artifact_name>-<GITHUB_SHA>` artifact through the GitHub API, downloads it into the release, and moves the contents of its `deployment-package` directory to the release root. |
| `deploy:verify-structure` | Fails the deploy if a `deployment-package` directory is still in the release. |
| `supervisor:reload` | Runs `supervisorctl reread`, then `supervisorctl update` for this site's groups only. |

| Setting | Default | Meaning |
| --- | --- | --- |
| `supervisor_groups` | (required) | This site's Supervisor groups. A program outside any group is its own group, named after the program. |
| `ci_artifact_repository` | read from `repository` | GitHub repository, for example `AustinMastLab/Biospex`. |
| `ci_artifact_name` | the repository name in lower case | Artifact prefix, for example `biospex` for `biospex-<sha>`. |
| `ci_artifacts_optional` | `false` | Outside GitHub Actions there is no artifact. `false` fails the deploy, so a manual deploy can't publish a release without its assets; `true` skips the download. |
| `deploy_umask` | (none) | Umask for the commands that write the artifact, for example `002` for group-writable files. |

The workflow must pass `GITHUB_TOKEN` and `GITHUB_SHA` to Deployer; GitHub Actions sets `GITHUB_ACTIONS=true` itself.

**Why `supervisor_groups` is required.** All sites share Supervisor on each server. `supervisorctl update` without a group applies every pending change on the server, so one site's deploy could restart another site's programs while that site's own deploy is half done. Naming the groups limits the update to this site.

| Site | `supervisor_groups` |
| --- | --- |
| BIOSPEX | `['biospex']` |
| Digitization Academy | `['da']` |
| WeDigBio reports | `['wedigbio-ingest']` |

## Managing parameters

Run these from your machine with AWS credentials that can write to SSM:

```bash
vendor/bin/push-env-params biospex development        # reads .env.aws.development
vendor/bin/push-env-params biospex production ./prod.env
vendor/bin/delete-env-param biospex production OPCACHE_WEBHOOK_TOKEN   # asks you to type the environment name
vendor/bin/remove-env-params biospex development      # asks you to type the environment name
```

`push-env-params` only adds or overwrites parameters. When you remove a line from `.env.aws.<environment>`, also delete the parameter with `delete-env-param`. Otherwise `env:ssm` keeps writing it into the server's `.env`. `delete-env-param` takes one or more keys, reports keys that don't exist, and skips them.

Keep `.env.aws.*` files out of git. Each site's `.gitignore` should cover `/.env.*`.

## Releasing

Tag a new version after changing anything, then update each site:

```bash
git tag v1.2.1 && git push origin v1.2.1

# in each site
composer update austinmastlab/deployer-recipes
```

Roll a change out with a development deploy of each site first. Check the deploy log for `Generated …` or `No changes …` from `env:ssm`.

| Version | Change |
| --- | --- |
| v1.2.0 | Adds `recipe/ci-artifacts.php`: `deploy:ci-artifacts`, `deploy:verify-structure` and `supervisor:reload`, which each site had its own copy of. `supervisor:reload` now updates only the site's own groups. |
| v1.1.0 | Adds `delete-env-param` to delete individual parameters. |
| v1.0.1 | Requires Deployer 8 (conflicts with `<8.0`). The `env:ssm` heredoc fails under Deployer 7. |
| v1.0.0 | First release: `env:ssm`, `generate-env`, `push-env-params`, `remove-env-params`. |

## Upgrading a site's packages

When you run `composer update` in a site, also check packages that extend framework commands. A Laravel update added a `--stop-when-empty-for` option to `queue:work`, and Horizon versions before 5.48 crash on it because `horizon:work` extends `queue:work`. Digitization Academy had pinned `laravel/horizon` to `5.35.*`, so its workers failed until Horizon was upgraded. Look at `composer outdated --direct` for packages held back by tight constraints, and after deploying check that queue workers stay up, not just that Supervisor shows RUNNING.
