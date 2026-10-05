# deployer-recipes

Shared [Deployer](https://deployer.org) recipes and AWS SSM Parameter Store tools for AustinMastLab sites. Each site loads this package with Composer, so every site uses the same versioned deploy logic instead of its own copy.

## Contents

| Path | Purpose |
| --- | --- |
| `recipe/ssm-env.php` | Deployer task `env:ssm`: generates the site's shared `.env` from SSM on the server. |
| `bin/generate-env` | Builds a `.env` from `/<app>/<environment>` in SSM. `env:ssm` runs it on the server. |
| `bin/push-env-params` | Pushes a local env file to SSM as `SecureString` parameters. |
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

To regenerate a `.env` by hand on a server:

```bash
ssh ubuntu@<server> 'bash -s -- biospex production' < bin/generate-env
```

## Managing parameters

Run these from your machine with AWS credentials that can write to SSM:

```bash
vendor/bin/push-env-params biospex development        # reads .env.aws.development
vendor/bin/push-env-params biospex production ./prod.env
vendor/bin/remove-env-params biospex development      # asks you to type the environment name
```

Keep `.env.aws.*` files out of git. Each site's `.gitignore` should cover `/.env.*`.

## Releasing

Tag a new version after changing anything. Sites pick it up with `composer update austinmastlab/deployer-recipes`.

```bash
git tag v1.0.1 && git push origin v1.0.1
```
