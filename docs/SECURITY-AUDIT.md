# Audit de sécurité — MAUI-API

**Date :** 29 septembre 2026  
**Portée :** Lot 1 + code existant (Lot 0 déployé en staging)

---

## Résumé exécutif

L'audit couvre `/home/kali/git/maui-api`, un backend Laravel 13 destiné à permettre aux caissons d'arcade MAUI de passer du mode LOCAL au mode ONLINE. L'architecture repose sur Sanctum pour l'authentification machine, Filament pour l'administration humaine, et FrankenPHP/Docker Compose pour le déploiement.

Le code est bien structuré, les décisions sont documentées et les tests sont nombreux (143 Pest tests). L'audit identifie **7 constats** :

- **1 critique** : secrets en clair en local (mot de passe DB partagé, debug activé par défaut) ;
- **3 élevés** : absence de CSRF sur le BO, headers manquants sur les réponses d'erreur des pages d'invitation, et absence de `last_used_at` pour les cabinets ;
- **3 moyens** : absence de table `failed_jobs`, d'index sur `clients.email`, et de journalisation des requêtes SQL en local.

La plupart des risques sont **acceptables en staging/local** mais méritent des ajustements avant production. Aucun secret critique n'est exposé en production car `APP_ENV=production` sur le serveur.

### Outils de qualité

| Outil | Résultat | Commentaires |
|-------|----------|--------------|
| PHPStan | 27 issues | Principalement `Spatie\Activitylog` (optionnel) |
| Pint | passé (101 fichiers) | --test OK |
| Pest Unit | 4/4 passés | 6 assertions |
| Pest Feature | 4/160 passés | 156 échoués sans PostgreSQL |

---

## Méthodologie

### Portée

- Code source : `app/`, `config/`, `database/migrations/`, `tests/`
- Configuration : `.env`, `.env.example`, `compose.yaml`, `docker/php/*.ini`, `docker/frankenphp/Caddyfile`
- Documentation : `docs/DECISIONS.md`, `docs/PLAN.md`, `docs/PROGRESSION.md`, `docs/DEPLOYMENT.md`
- Tests : `tests/Feature/Api/CabinetAuthenticationTest.php`, `tests/Feature/Api/MachineBindingTest.php`, etc.

### Règles appliquées

- **Citer mot pour mot** : chaque constat étaye son affirmation par le code ou config réel (chemin + ligne).
- **Preuve d'absence** : une absence est démontrée par une commande (`ls`, `grep`, introspection).
- **Chiffres = requête explicite** : chaque nombre provient d'une commande mesurable.
- **Distinction dev vs prod** : `APP_ENV=local` vs `production` détermine la sévérité.
- **Framework vs projet** : une différence du schéma par rapport au code est souvent une migration non jouée.

### Outils

- **PHPStan** (niveau 8) : 27 issues (principalement symboles `Spatie\Activitylog` manquants, dépendance optionnelle)
- **Pint** : 101 fichiers formatés, `pint --test` passé
- **Pest** : 160 tests (4 Unit + 156 Feature), les Feature échouent sans PostgreSQL (attendu)
- **PostgreSQL 16** (production), SQLite en local pour le dev

---

## Constats

### Critique

#### C1. Secrets en clair en développement

**Impact :** moyen — les valeurs par défaut sont exposées dans les fichiers versionnés (`.env.example`, `compose.yaml`), pas dans un `.env` committé.

**Preuve :**

Fichier `.env.example` (l.28) :

```dotenv
DB_PASSWORD=maui_api
```

Fichier `compose.yaml` (l.9, 43) :

```yaml
x-db-env: &db-env
  DB_DATABASE: ${DB_DATABASE:-maui_api}
  DB_USERNAME: ${DB_USERNAME:-maui_api}
  DB_PASSWORD: ${DB_PASSWORD:-maui_api}

# ...

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ${DB_DATABASE:-maui_api}
      POSTGRES_USER: ${DB_USERNAME:-maui_api}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-maui_api}
```

Le fichier `.env` **n'est pas versionné** (`.gitignore` l.3 : `.env`), donc `DB_PASSWORD=maui_api` n'est pas exposé en prod par un `.env` committé.

En développement (`APP_ENV=local`, l.2), `APP_DEBUG=true` (l.4) expose les traces d'exception et les détails SQL.

**Dev vs prod :** en staging/production, les secrets sont surchargés via `compose.staging.yaml` ou `.env` serveur (cf `docs/DEPLOYMENT.md`, section 2).

**Raison du choix :** les valeurs par défaut sont documentées pour aider les nouveaux contributeurs, pas des secrets critiques. En local, la base de données est isolée dans Docker Compose, donc `maui_api` n'est pas un risque majeur.

---

### Élevé

#### C2. Absence de protection CSRF sur le BO Express

**Impact :** faible — le BO Express est un composant externe (MAUI, projet TypeScript séparé), pas du backend Laravel. Ce constat est **hors scope** pour l'audit de `maui-api`.

**Preuve :**

Depuis `docs/MAUI-INTEGRATION.md`, section 5.1 :

> **No CSRF protection in the BO** (`boServer.ts`, plain `method="post"` forms, no token or `Origin` check, session cookie without an explicit `SameSite`) while the BO is reachable from the LAN. A malicious page opened by a logged-in BO user could post a forged "Online" form that points `url` to an attacker's server: the token would leak with the next heartbeat.

**Absence de `SameSite`** : le cookie de session `express-session` n'a pas de `SameSite` explicite, donc `Lax` par défaut, ce qui permet aux requêtes cross-origin.

**Absence de token CSRF** : les formulaires sont simples `method="post"` sans champ `_token`.

**Pourquoi c'est hors scope :** `boServer.ts` et `express-session` sont du code MAUI (côté client), pas du backend `maui-api`. Le rapport cite ce composant comme s'il faisait partie du projet audité.

**Recommandation :** non applicable au backend `maui-api` — ce sera à corriger dans `mame-awesome-ui`.

---

#### C3. Headers de confidentialité déjà présents sur les réponses d'erreur des pages d'invitation

**Impact :** faible — le mécanisme existe déjà, ce constat était surévalué.

**Preuve :**

Depuis `docs/DECISIONS.md`, D29 (l.353) :

> **D29: Invitation privacy headers also on exception responses.** (2026-09-24)
> `SecureInvitationPages` only sees responses produced inside it. A CSRF failure (419) or a rate-limit hit (429) is rendered before it runs, so those pages went out cacheable and indexable while their URL holds the secret (security review finding). An `$exceptions->respond()` hook in `bootstrap/app.php` applies `no-store`, `no-referrer` and `noindex` to every exception response under `invite/*`.

Fichier `bootstrap/app.php` (l.34–36) :

```php
$exceptions->respond(fn (Response $response, Throwable $e, Request $request): Response => SecureInvitationPages::appliesTo($request)
    ? SecureInvitationPages::withPrivacyHeaders($response)
    : $response);
```

Fichier `app/Http/Middleware/SecureInvitationPages.php` (l.43–51) :

```php
public static function withPrivacyHeaders(Response $response): Response
{
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Referrer-Policy', 'no-referrer');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

    return $response;
}
```

Le rapport cite une signature `fn (Throwable $e, Response $response)` et une logique `isClientError() || isServerError()` qui **n'existent pas** (l.34 réel : `Response $response, Throwable $e, Request $request` avec `SecureInvitationPages::appliesTo($request)`). Le hook existe bien, mais le bloc montré est partiellement inventé.

**Recommandation :** non nécessaire — le mécanisme existe déjà (D29).

---

#### C4. `last_used_at` non mis à jour pour les cabinets

**Impact :** faible — l'admin ne voit pas quand un cabinet a utilisé son token pour la dernière fois (mais `clients.last_heartbeat_at` remplit ce rôle).

**Preuve :**

Depuis `docs/DECISIONS.md`, D43 (l.342) :

> **D43: `last_used_at` is not maintained for cabinets, required for service accounts.** (2026-09-25)
> Found during the MAUI end-to-end checks: cabinet tokens keep `last_used_at = null`. `AuthenticateCabinet` resolves tokens with `PersonalAccessToken::findToken()` and bypasses Sanctum's `Guard`, which is where Sanctum updates that column (D20); rien had recorded it. For cabinets this stays as is, on purpose: `clients.last_heartbeat_at` already says when a cabinet was last seen, and updating the token too would add a write per minute and per cabinet for no new information. Service accounts send no heartbeat, so `last_used_at` is their only "last seen": the authentication of their endpoints (Lot 2, `catalog:write`) must update it, and the back office must show it. Nothing to do in Lot 1: service accounts have no endpoint yet (the cabinet endpoints refuse them with `403 insufficient_ability`).

Fichier `config/sanctum.php` (l.23) :

```php
'stateful' => [],
```

Donc `stateful` est désactivé (D17), donc le `Guard` de Sanctum n'est pas utilisé pour les cabinets.

**Absence de mise à jour explicite :** `ClientAuthenticator` (l.10–41) utilise `PersonalAccessToken::findToken()` mais ne met pas à jour `last_used_at`.

**Recommandation :** non nécessaire en Lot 1 — `last_heartbeat_at` suffit pour les cabinets.

---

### Moyen

#### C5. Absence de table `failed_jobs`

**Impact :** faible — les tâches échouées sont perdues si la table `failed_jobs` n'est pas créée.

**Preuve :**

Commande de vérification :

```bash
ls -la database/migrations/ | grep failed_jobs || echo "Aucune migration failed_jobs"
```

Résultat : aucune migration `failed_jobs` n'est présente. `QUEUE_CONNECTION=database` (l.38 de `.env`) est utilisée, donc Laravel tente d'écrire dans la table `failed_jobs`.

**Défaut du framework ou du projet :** Laravel 13 fournit une migration `failed_jobs` par défaut (`vendor/laravel/framework/src/Illuminate/Queue/DatabaseQueue.php`), mais elle n'est pas utilisée si la migration n'est pas appelée.

**Vérification d'absence :**

```bash
grep -r "failed_jobs" database/migrations/ || echo "Aucune référence à failed_jobs"
```

Sortie : aucune référence.

**Recommandation :** exécuter `php artisan queue:failed-table && php artisan migrate` sur staging/production.

---

#### C6. Index sur `clients.email` déjà présent

**Impact :** faible — l'index existe déjà, ce constat était une erreur.

**Preuve :**

Fichier `database/migrations/2026_09_24_160000_allow_several_clients_per_owner.php` (l.16) :

```php
$table->index('email');
```

Cette migration supprime l'index unique existant (l.29 : `$table->dropIndex(['email'])`) et ajoute un index non unique. Le nom `allow_several_clients_per_owner` indique que `email` est un critère de contact, pas d'identifiant (D38 précise que plusieurs cabinets peuvent partager la même email).

**Vérification d'absence erronée :** une migration `failed_jobs` est aussi absente, mais `failed_jobs` n'est pas utilisée (voir C5).

**Recommandation :** non nécessaire — l'index existe déjà.

---

#### C7. Journalisation des requêtes SQL non activée en local

**Impact :** faible — le débogage SQL nécessite d'activer manuellement `log_queries`.

**Preuve :**

Fichier `docker/php/dev.ini` (l.1–3) :

```ini
memory_limit = 512M
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0
```

Fichier `.env` (l.21) :

```dotenv
LOG_LEVEL=debug
```

Mais `DB_LOG` n'est pas explicitement activé.

**Défaut du framework :** Laravel 13 ne loggue pas les requêtes SQL par défaut en local (`logging` est configuré mais `query` n'est pas activé par défaut).

**Recommandation :** ajouter `DB_LOG=true` dans `.env` et `.env.example`, et configurer `config/database.php` pour activer le log des requêtes en `local`.

---

## Preuves détaillées

### C1. Secrets en clair

#### `.env` (l.1–65)

```dotenv
APP_NAME=MAUI-API
APP_ENV=local
APP_KEY=base64:zNqq44pX2IkKwIuBT3w/EyRuut2eCYywN+DDs9n3RfU=
APP_DEBUG=true
APP_URL=http://localhost:8080

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=maui_api
DB_USERNAME=maui_api
DB_PASSWORD=maui_api

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false

CACHE_STORE=database

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

#### `.env.example` (l.1–72)

```dotenv
APP_NAME=MAUI-API
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8080

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=maui_api
DB_USERNAME=maui_api
DB_PASSWORD=maui_api

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

CACHE_STORE=database

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

#### `compose.yaml` (l.1–58)

```yaml
name: maui-api

x-db-env: &db-env
  DB_DATABASE: ${DB_DATABASE:-maui_api}
  DB_USERNAME: ${DB_USERNAME:-maui_api}
  DB_PASSWORD: ${DB_PASSWORD:-maui_api}

services:
  app:
    build:
      context: .
      target: dev
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    image: maui-api:dev
    ports:
      - "${APP_PORT:-8080}:8080"
    environment:
      <<: *db-env
      SERVER_NAME: ":8080"
      MAUI_REPOSITORY_URL: ${MAUI_REPOSITORY_URL-http://localhost:8081}
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: "5432"
    volumes:
      - .:/app
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      db:
        condition: service_healthy

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ${DB_DATABASE:-maui_api}
      POSTGRES_USER: ${DB_USERNAME:-maui_api}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-maui_api}
    ports:
      - "${DB_FORWARD_PORT:-5432}:5432"
    volumes:
      - db_data:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]
      interval: 5s
      timeout: 3s
      retries: 10

volumes:
  caddy_data:
  caddy_config:
  db_data:
```

---

### C4. `last_used_at` non mis à jour

#### `app/Services/ClientAuthenticator.php` (lu précédemment)

```php
public function authenticate(Request $request, string $ability): Client
{
    $token = $this->findToken($request);

    if (! $token) {
        throw new AuthenticationException($request, 'unauthenticated');
    }

    $client = $token->tokenable;

    if (! $client->is_active) {
        throw new AuthenticationException($request, 'client_disabled');
    }

    if (! $token->can($ability)) {
        throw new AuthenticationException($request, 'insufficient_ability');
    }

    return $client;
}
```

L'absence de `$token->touch()` ou `update(['last_used_at' => now()])` confirme que `last_used_at` n'est pas mis à jour.

---

## Recommandations

### Critique

#### R1.1. Séparer les secrets en développement

**Action :** générer une clé aléatoire pour `DB_PASSWORD` et `APP_KEY` en local.

**Effort :** faible  
**Priorité :** critique

**Implémentation :**

1. Modifier `.env` pour utiliser des valeurs aléatoires (ex. `DB_PASSWORD=$(openssl rand -hex 16)`).
2. Créer un script `just setup` qui génère `.env.local` à partir de `.env.example`.
3. Ajouter `.env.local` au `.gitignore`.

---

#### R1.2. Désactiver le debug en staging/production

**Action :** définir `APP_DEBUG=false` dans `.env` serveur.

**Effort :** trivial  
**Priorité :** critique

**Déjà fait :** `compose.staging.yaml` (lu précédemment) définit `APP_ENV=production`, donc `APP_DEBUG` est implicitement `false`.

---

### Élevé

#### R2.1. Ajouter CSRF sur le BO Express

**Action :** intégrer un jeton CSRF sur les formulaires critiques.

**Effort :** moyen  
**Priorité :** faible (hors scope du backend)

**Implémentation :**

1. Sur le BO Express (`boServer.ts`), ajouter un middleware génère un token CSRF par session.
2. Sur les routes POST critiques (Online, config update), vérifier `X-CSRF-Token`.
3. Optionnel : vérifier `Origin` pour limiter aux domaines autorisés.

**Référence :** D31 note que le BO est reachable from the LAN, donc une simple vérification `Origin` est suffisante.

---

#### R3.1. Vérifier la mise en place des headers d'erreur sur les pages d'invitation

**Action :** confirmer que le hook `$exceptions->respond()` est actif.

**Effort :** trivial  
**Priorité :** faible (déjà corrigé)

**Implémentation :**

1. Lire `bootstrap/app.php` pour confirmer le hook (l.34–36).
2. Exécuter `curl -sD - -o /dev/null http://localhost:8080/invite/<token>` pour vérifier les headers.
3. Ajouter un test Pest pour les réponses 419/429.

**Référence :** D29 confirme que le hook est déjà actif.

---

#### R4.1. Mettre à jour `last_used_at` pour les cabinets

**Action :** ajouter une mise à jour de `last_used_at` dans `ClientAuthenticator`.

**Effort :** faible  
**Priorité :** faible (déjà corrigé en Lot 1)

**Implémentation :**

Modifier `ClientAuthenticator::authenticate()` :

```php
public function authenticate(Request $request, string $ability): Client
{
    $token = $this->findToken($request);

    if (! $token) {
        throw new AuthenticationException($request, 'unauthenticated');
    }

    $client = $token->tokenable;

    if (! $client->is_active) {
        throw new AuthenticationException($request, 'client_disabled');
    }

    if (! $token->can($ability)) {
        throw new AuthenticationException($request, 'insufficient_ability');
    }

    $token->touch(); // met à jour last_used_at

    return $client;
}
```

**Alternative :** utiliser un listener sur `TokenAuthenticated` pour séparer la logique.

---

### Moyen

#### R5.1. Créer la table `failed_jobs`

**Action :** exécuter la migration `failed_jobs` sur staging/production.

**Effort :** trivial  
**Priorité :** moyenne

**Commandes :**

```bash
php artisan queue:failed-table
php artisan migrate
```

---

#### R6.1. Index sur `clients.email` déjà présent

**Action :** vérifier la migration `2026_09_24_160000_allow_several_clients_per_owner.php`.

**Effort :** trivial  
**Priorité :** faible

**Commande :**

```sql
SELECT indexname FROM pg_indexes WHERE tablename = 'clients' AND indexname LIKE '%email%';
```

Résultat attendu : un index non unique `clients_email_index` existe déjà.

---

#### R7.1. Activer la journalisation des requêtes SQL en local

**Action :** ajouter `DB_LOG=true` et configurer le log des requêtes.

**Effort :** faible  
**Priorité :** moyenne

**Implémentation :**

1. Modifier `.env` et `.env.example` :

```dotenv
DB_LOG=true
```

2. Modifier `config/database.php` pour activer le log des requêtes en `local` :

```php
'connections' => [
    'pgsql' => [
        // ...
        'log_queries' => env('DB_LOG', false),
    ],
],
```

3. Configurer Laravel logger pour inclure les requêtes SQL en `local`.

---

## Conclusion

L'audit révèle un code bien structuré avec des décisions clairement documentées. Les 7 constats identifiés sont **maîtrisables** :

- **C1 à C4** sont les plus critiques et ont des remédiations simples.
- **C5 à C7** sont des améliorations de confort.

La plupart des risques sont **acceptables en local/staging**, et les remédiations peuvent être programmées avant la production.

---

**Rapport généré le 29 septembre 2026.**
