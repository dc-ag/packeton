# UPGRADE

## #210226 — New endpoint: `/api/packages/{name}/current-dependents.json`

A new API endpoint has been added to expose **current dependents** — packages that depend on a given
package in their latest current (dev) version only. Historical-only dependents are excluded.

### Endpoint

```
GET /api/packages/{name}/current-dependents.json
```

### Query parameters

| Parameter     | Required | Description |
|---------------|----------|-------------|
| `page`        | No       | Page number (default `1`, 15 items per page) |
| `version_tag` | No       | Substring filter on the dependent's current version string |

### Response shape

```json
{
  "name": "vendor/package",
  "count": 2,
  "packages": [
    {
      "id": 1,
      "name": "vendor/dependent-a",
      "description": "...",
      "dependency_type": "require"
    },
    {
      "id": 2,
      "name": "vendor/dependent-b",
      "description": "...",
      "dependency_type": "require-dev"
    }
  ]
}
```

### Semantics

- **current/latest only**: only the single highest semVer version per dependent package is considered
  (branch aliases such as `dev-master` are excluded; among remaining versions the one with the highest
  `normalizedVersion`, tie-broken by `id DESC`, is selected). Packages whose highest version no longer
  requires the target are **not** included.
- **`dependency_type`**: `require` or `require-dev`. If a package appears in both for the same target,
  it is returned **once** with `dependency_type = require` (`require` wins over `require-dev`).
- **`version_tag`**: substring match (`LIKE %value%`) applied **before** selecting the highest version —
  only versions whose `version` string contains the tag are eligible as the "current" version.
  This means a package can appear in filtered results even if its absolute latest version does not match
  the tag, as long as the highest matching version still has the dependency.
  List and count queries use identical filter semantics, so pagination totals are always consistent.
- The existing `/api/packages/{name}/dependents` endpoint is **unchanged**.

---

## UPGRADE FROM 1.4 to 2.0

- Require PHP 8.1+
- Symfony LTS has been changed from `3.4` to `5.4`
- Application composer HOME was changed to `%kernel.project_dir%/var/.composer`

#### Run migrations
There are no major BC breaks in the database structure. 

Run the command to show SQL changes:

```
bin/console doctrine:schema:update --dump-sql
```

Run the command to execute migrations:

```
bin/console doctrine:schema:update --force
```

*Note* 
Now composer v2 metadata is supported. The app also supports composer v1 too.
No additional action required.

### Docker UPGRADE

- Image has been renamed from [okvpn/packeton](https://hub.docker.com/r/okvpn/packeton) to [packeton/packeton](https://hub.docker.com/r/packeton/packeton)
- Volume directory structure was changed:
  - /data/composer - composer home
  - /data/redis - redis data
  - /data/zipball - dist path
  - /data/ssh - ssh for www-data path

Before:

```
    - .docker/redis:/var/lib/redis
    - .docker/zipball:/var/www/packagist/app/zipball
    - .docker/composer:/var/www/.composer
    - .docker/ssh:/var/www/.ssh
```

After:

```
    - .docker:/data
```

#### Env variables update

Before `docker-compose.yml`

```yaml
    environment:
        DATABASE_HOST: postgres
        DATABASE_PORT: 5432
        DATABASE_DRIVER: pdo_pgsql
        DATABASE_USER: postgres
        DATABASE_NAME: packagist
        DATABASE_PASSWORD: 123456
```

After:

```yaml
    environment:
        DATABASE_URL: mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8&charset=utf8mb4
```

## UPGRADE FROM 2.0 to 2.1

The metadata root format `/packages.json` has been changed. Now it is configurable. By default, for performance it depends on Composer `User-Agent`
If you use `/packages.json` API directly (without composer) please add query parameter `ua=1` for 1 format.
See [metadata configuration](/README.md#configuration)

Before:
```json
{
    "packages": [],
    "notify": "/downloads/%package%",
    "notify-batch": "/downloads/",
    "metadata-changes-url": "/metadata/changes.json",
    "providers-url": "/p/%package%$%hash%.json",
    "metadata-url": "/p2/%package%.json",
    "provider-includes": {
        "p/providers$%hash%.json": {
            "sha256": "af2f8f8f8b403ef309e0aca59b080b65fd7465ede21c12a157a1422af8001f42"
        }
    },
    "available-packages": ["okvpn/cron-bundle"]
}
```

After:

```json
{
    "packages": [],
    "notify": "/downloads/%package%",
    "notify-batch": "/downloads/",
    "metadata-changes-url": "/metadata/changes.json",
    "providers-url": "/p/%package%$%hash%.json",
    "metadata-url": "/p2/%package%.json",
    "available-packages": ["okvpn/cron-bundle"]
}
```

The lazy route `/p/%package%.json` now return Сomposer compatible data:

```
{"packages": {"name": {versions}}
```
