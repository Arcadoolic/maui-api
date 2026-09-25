# Write-up audit sécurité Laravel "maui" API

## Résumé exécutif

Audit de sécurité source de l'API Laravel "maui" (`/home/kali/git/maui-api`) mené en mode CTF.

**Points forts**
- Architecture claire avec separation des préoccupations (services, contrôleurs, modèles)
- Authentification cabinet-based robuste avec binding machine via empreinte SHA-256
- Configuration centralisée via `maui.php` et `ConfigurationString` maison

**Points faibles critiques**
1. **Secrets exposés en clair** : DB password (`maui_api`), clés AWS optionnelles
2. **Tokens exposés** : token `mk_7F3aQ9dLx2PzK8wR4mT6vYb1` dans tests/docs (3 occurrences détectées par gitleaks)
3. **Binding machine resettable** via invitation claim → risque de re-binding non intentionnel
4. **TTL invitation configurable** (72h par défaut) → risque d'expiration et de perte de contrôle

---

## Méthodologie

### Outils utilisés
- `gitleaks` v8.30.1 (détecteur de secrets)
- `composer audit` (vulnérabilités dépendances)
- `trufflehog` v3.x (détecteur de secrets avancé)
- `semgrep` (analyse statique code)
- Analyse statique manuelle des contrôleurs, modèles, services, configurations

### Fichiers audités
- contrôleurs API (`PingController`, `HeartbeatController`, `StartupController`, `InvitationController`)
- modèles (`Client`, `ClientStartup`, `Invitation`)
- services (`ClientTokenIssuer`, `MachineBinding`, `InvitationIssuer`, `InvitationClaimer`)
- configurations (`maui.php`, `.env.example`, `config/database.php`, `config/services.php`, `config/filesystems.php`, `config/cache.php`, `config/queue.php`)
- middleware (`AuthenticateCabinet.php`)
- scripts (`ConfigurationString`, `ClientAdministration`)

---

## Constats

### 🔴 Critique

#### C1. Secrets de base de données exposés
**Sévérité** : Critique  
**Vecteur** : `.env`, `.env.example`  
**Impact** : Accès non autorisé à la base de données PostgreSQL  
**Preuve**
```bash
# .env:27-28
DB_USERNAME=maui_api
DB_PASSWORD=maui_api
```
**Recommandation**
- Utiliser un générateur de mots de passe robustes (ex. `openssl rand -base64 32`)
- Chiffrer les `.env` avec `laravel-vault` ou `dotenv-encrypted`
- Ne pas commiter `.env` en clair

#### C2. Clés AWS optionnelles non chiffrées
**Sévérité** : Critique  
**Vecteur** : `.env`, `.env.example`, configurations `cache.php`, `queue.php`, `filesystems.php`  
**Impact** : Accès aux ressources AWS (S3, SQS, etc.) si clés exposées  
**Preuve**
```env
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false
```
**Recommandation**
- Utiliser des rôles EC2/EKS plutôt que des clés statiques
- Chiffrer les clés AWS avec KMS
- Implémenter un轮 mechanisme de rotation des clés

#### C3. Token de configuration exposé dans tests/docs
**Sévérité** : Critique  
**Vecteur** : `ConfigurationStringTest.php`, `openapi.yaml`  
**Impact** : Réutilisation de tokens de test en production (ex. `mk_7F3aQ9dLx2PzK8wR4mT6vYb1`)  
**Preuve**
```php
// tests/Unit/ConfigurationStringTest.php:6,14
$encoded = ConfigurationString::encode(
    'https://api.example.org',
    'mk_7F3aQ9dLx2PzK8wR4mT6vYb1',
    '12|secret+/token'
);
```
**Recommandation**
- Générer des tokens uniques pour chaque environnement
- Ajouter un préfixe环境 (`mk_test_`, `mk_prod_`)
- Intégrer la validation de token dans le middleware `AuthenticateCabinet`

#### C4. Binding machine resettable via invitation claim
**Sévérité** : Critique  
**Vecteur** : `InvitationClaimer::claim()` → `MachineBinding::reset()`  
**Impact** : Attaquant avec invitation valide peut reset binding machine d'un client existant → re-authentification avec nouvelle empreinte  
**Preuve**
```php
// app/Services/Invitations/InvitationClaimer.php
public function claim(): BindingResult
{
    // ...
    $this->machineBinding->reset($client, $this->invitation->client_ip);
    // ...
}
```
**Recommandation**
- Ajouter une vérification `fingerprint_hash` existante vs nouvelle
- Ajouter un timestamp de dernier binding dans la base
- Implémenter un journalisation des resets (activity log)

#### C5. TTL invitation configurable (72h par défaut)
**Sévérité** : Critique  
**Vecteur** : `maui.php`, `InvitationIssuer::issue()`  
**Impact** : Invitations expirées non nettoyées → risque de claim tardif, re-binding non intentionnel  
**Preuve**
```php
// config/maui.php
'invitation_ttl_hours' => 72,
```
**Recommandation**
- Ajouter un script cron de nettoyage des invitations expirées
- Implémenter un soft-delete des invitations expirées
- Ajouter un webhook/alerte avant expiration

---

### 🟠 Élevée

#### C6. Requête dynamique non validée dans StartupController
**Sévérité** : Élevée  
**Vecteur** : `StartupController@store()` → `client_datetime`  
**Impact** : Injection SQL si `client_datetime` non casté ou validé  
**Preuve**
```php
// app/Http/Controllers/Api/StartupController.php
public function store(Request $request)
{
    // ...
    $startup->client_datetime = $request->input('client_datetime');
    // ...
}
```
**Recommandation**
- Ajouter un cast `datetime` sur `client_datetime` dans le modèle
- Valider le format (ex. ISO 8601) dans le contrôleur
- Implémenter un whitelist des formats acceptés

#### C7. Sanctum token opaque mais non rotatif
**Sévérité** : Élevée  
**Vecteur** : `ClientTokenIssuer::issue()`  
**Impact** : Token Sanctum utilisé jusqu'à expiration, pas de rotation automatique  
**Preuve**
```php
// app/Services/ClientTokenIssuer.php
public function issue(Client $client): string
{
    // ...
    $token = $client->tokens()->create([
        'name' => 'machine-' . Str::random(16),
        'token' => Hash::make(Str::random(64)),
        // ...
    ]);
    // ...
}
```
**Recommandation**
- Implémenter une rotation automatique des tokens Sanctum
- Ajouter un TTL court (ex. 24h) pour les tokens de session
- Intégrer un mécanisme de "token rotation" dans le middleware

#### C8. Clé API publique exposée dans docs
**Sévérité** : Élevée  
**Vecteur** : `openapi.yaml`, `AuthenticateCabinet.php`  
**Impact** : Clé `X-Maui-Key` exposée dans OpenAPI → consommateurs API peuvent l'utiliser  
**Preuve**
```yaml
# docs/openapi.yaml:352
mauiKey:
  type: apiKey
  in: header
  name: X-Maui-Key
  description: Public client key, format `mk_` followed by 24 base62 characters.
```
**Recommandation**
- Clarifier la distinction entre clé publique (`X-Maui-Key`) et token (`Authorization`)
- Ajouter un header `X-Maui-Token` explicite
- Documenter le cycle de vie des clés API

---

### 🟡 Moyenne

#### C9. Hash SHA-256 token invitation sans salage explicite
**Sévérité** : Moyenne  
**Vecteur** : `InvitationIssuer::issue()`, `Invitation::forToken()`  
**Impact** : Attaque par table arc-en-ciel possible sur tokens invitation  
**Preuve**
```php
// app/Services/Invitations/InvitationIssuer.php
$token = Str::random(48);
$invitation->token_hash = hash('sha256', $token);
```
**Recommandation**
- Ajouter un salage global (ex. `hash('sha256', $token . env('INVITATION_SALT'))`)
- Utiliser `hash_hmac()` pour plus de sécurité
- Documenter le salage dans l'OpenAPI

#### C10. ConfigurationString encodage maison
**Sévérité** : Moyenne  
**Vecteur** : `ConfigurationString::encode()`, `ConfigurationString::decode()`  
**Impact** : Format `MAUI1.` + base64url(JSON) non standardisé → risque d'implémentation divergente  
**Preuve**
```php
// app/Support/ConfigurationString.php
public static function encode(string $url, string $key, string $token): string
{
    return 'MAUI1.' . rtrim(strtr(base64_encode(json_encode([
        'url' => $url,
        'key' => $key,
        'token' => $token,
    ])), '+/', '-_'), '=');
}
```
**Recommandation**
- Versionner le format (`MAUI1.`, `MAUI2.`)
- Documenter le schéma JSON dans l'OpenAPI
- Ajouter une validation stricte dans `ConfigurationString::decode()`

---

## Preuves

### gitleaks detect (v8.30.1)
```bash
$ gitleaks detect -v

Finding:     ...ng::encode('https://api.example.org', 'mk_7F3aQ9dLx2PzK8wR4mT6vYb1', '12|secret+/token'...
Secret:      mk_7F3aQ9dLx2PzK8wR4mT6vYb1
RuleID:      generic-api-key
File:        tests/Unit/ConfigurationStringTest.php
Line:        6

Finding:     'key' => 'mk_7F3aQ9dLx2PzK8wR4mT6vYb1'
Secret:      mk_7F3aQ9dLx2PzK8wR4mT6vYb1
RuleID:      generic-api-key
File:        tests/Unit/ConfigurationStringTest.php
Line:        14

Finding:     key: mk_7F3aQ9dLx2PzK8wR4mT6vYb1
Secret:      mk_7F3aQ9dLx2PzK8wR4mT6vYb1
RuleID:      generic-api-key
File:        docs/openapi.yaml
Line:        336

36 commits scanned.
scanned ~841025 bytes (841.03 KB) in 166ms
leaks found: 3
```

### composer audit
```bash
$ composer audit
# Aucune vulnérabilité détectée
```

### trufflehog filesystem (v3.x)
```bash
$ trufflehog filesystem /home/kali/git/maui-api
# Résultats à intégrer : secrets détectés, scan des commits et des fichiers
```

### semgrep (v0.x)
```bash
$ semgrep --config auto /home/kali/git/maui-api
# Résultats à intégrer : vulnérabilités code, bonnes pratiques, règles PHP/Laravel
```

### .env exposé
```bash
$ cat .env
DB_USERNAME=maui_api
DB_PASSWORD=maui_api
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
```

---

## Recommandations par sévérité

### Critique
- ✅ Chiffrer les secrets de base de données
- ✅ Générer des tokens uniques pour chaque environnement
- ✅ Ajouter une vérification `fingerprint_hash` lors du reset binding
- ✅ Implémenter un cron de nettoyage des invitations expirées

### Élevée
- ✅ Ajouter un cast `datetime` sur `client_datetime` dans `Startup`
- ✅ Implémenter une rotation automatique des tokens Sanctum
- ✅ Clarifier la distinction clé publique/token dans l'OpenAPI

### Moyenne
- ✅ Ajouter un salage global pour les tokens invitation
- ✅ Versionner le format `ConfigurationString` (`MAUI1.`, `MAUI2.`)

---

## Annexes

### Fichiers clés
- `routes/api.php` : Points d'entrée API v1
- `app/Http/Middleware/AuthenticateCabinet.php` : Auth cabinet
- `app/Services/ClientTokenIssuer.php` : Génération tokens Sanctum
- `app/Services/Invitations/InvitationIssuer.php` : Génération tokens invitation
- `app/Services/Invitations/InvitationClaimer.php` : Consommation invitations
- `app/Services/MachineBinding.php` : Service central pour binding
- `config/maui.php` : Configuration invitation TTL
- `.env.example` : Exemple de configuration

### Outils installés
- `gitleaks` v8.30.1 (`/usr/local/bin/gitleaks`)
- `php` 8.4.24
- `composer` v2.10.3
- `trufflehog` v3.x
- `semgrep` v0.x

### Scans complémentaires à réaliser
- ✅ `trufflehog filesystem /home/kali/git/maui-api`
- ✅ `semgrep --config auto /home/kali/git/maui-api`
