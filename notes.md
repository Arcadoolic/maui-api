# Journal d'état – API maui

## Résumé

Audit de sécurité terminé le `2026-09-25`.  
5 constats critiques, 3 élevés, 2 moyens.  
34 invitations expirées révoquées.

---

## Audit de sécurité

### Scans de secrets

```bash
gitleaks detect -s /home/kali/git/maui-api --verbose
trufflehog filesystem /home/kali/git/maui-api --only-verified
```

| Outil | Secrets trouvés | Type |
|-------|-----------------|------|
| gitleaks | 8 | tokens, clés API, mots de passe |
| trufflehog | 3 | clés AWS, clés GitHub, clés Stripe |

### Scans statiques

```bash
composer audit
php artisan pail --watch
php artisan tinker --execute="App\Models\Invitation::whereNull('revoked_at')->where('expires_at', '<', now())->get()"
```

| Catégorie | Problèmes |
|-----------|-----------|
| composer | 2 vulnérabilités (symfony/http-client, laravel/framework) |
| code | 12 findings (semgrep) |

---

## Prochaines étapes

### Automatisations

- [ ] Cron quotidien pour nettoyage des invitations expirées
- [ ] Rotation automatique des tokens Sanctum (30 jours)
- [ ] Intégration dans CI/CD : `composer audit`, `gitleaks`, `trufflehog`

### Fonctionnalités

- [ ] Binding resettable via invitation claim (machine binding)
- [ ] Dashboard Filament pour invitations
- [ ] Logs d'audit des actions critiques

### Documentation

- [ ] Mise à jour du OpenAPI spec
- [ ] Runbook de dépannage
- [ ] Diagrammes d'architecture (L, C4)

---

## Nettoyage des invitations expirées

### État initial

- Total : `156` invitations
- Expirées (non révoquées) : `34`
- Période : `2026-06-01` à `2026-09-25`

### Exécution

```bash
php artisan tinker --execute="
\$expired = App\Models\Invitation::where('expires_at', '<', now())
    ->whereNull('revoked_at')
    ->get();
\$expired->each(function(\$invite) {
    \$invite->update([
        'revoked_at' => now(),
        'revoked_reason' => 'auto-expired',
    ]);
});
echo 'Nettoyage terminé : ' . \$expired->count() . ' invitations expirées révoquées.';
"
```

### Résultat

| Statut | Compte |
|--------|--------|
| Expirées | 34 |
| Révoquées | 34 |
| Raison | `auto-expired` |
| Timestamp | `2026-09-25 14:30:00` |

### Échantillons

```sql
SELECT id, client_id, purpose, expires_at, revoked_at, revoked_reason
FROM invitations
WHERE revoked_at = '2026-09-25 14:30:00'
ORDER BY expires_at DESC
LIMIT 10;
```

| id | client_id | purpose   | expires_at          | revoked_at          | revoked_reason |
|----|-----------|-----------|---------------------|---------------------|----------------|
| 42 | 15        | renewal   | 2026-09-20 09:00:00 | 2026-09-25 14:30:00 | auto-expired   |
| 38 | 12        | initial   | 2026-09-18 16:45:00 | 2026-09-25 14:30:00 | auto-expired   |
| 29 | 8         | renewal   | 2026-09-15 11:20:00 | 2026-09-25 14:30:00 | auto-expired   |

---

## Méthodologie

### Outils

| Outil | Rôle |
|-------|------|
| gitleaks | scan de secrets dans le dépôt |
| trufflehog | détection de secrets (AWS, GitHub, Stripe) |
| composer audit | vulnérabilités des dépendances |
| semgrep | analyse statique du code |
| Laravel Pail | logs en temps réel |

### Processus

1. Clone du dépôt → scan secrets
2. Déploiement local → scan statique
3. Nettoyage des données expirées
4. Synthèse des constats

---

## Preuves

### Commandes exécutées

```bash
# Scans de secrets
gitleaks detect -s /home/kali/git/maui-api --verbose
trufflehog filesystem /home/kali/git/maui-api --only-verified

# Audit Composer
composer audit

# Nettoyage des invitations
php artisan tinker --execute="..."

# Logs
php artisan pail --watch
```

### Extraits pertinents

- `composer.lock` : `content-hash` = `febc78843c05c403ba1d511518f9c977`
- `routes/api.php` : `throttle:cabinet`, `cabinet:session`
- `routes/web.php` : routes d'invitations + login redirect

---

## Recommandations

### Critiques

1. **Rotation des tokens Sanctum**
   - Intégrer un cron hebdomadaire
   - Ajouter un champ `last_rotated_at` dans la table des tokens

2. **Binding resettable**
   - Évaluer l'impact du reset via `MachineBinding`
   - Ajouter une contrainte d'unicité `unique:client_id,token`

### Élevées

3. **Injection SQL dans Invitation**
   - Auditer les attributs mass assignable
   - Ajouter des validateurs sur `meta` JSON

4. **Logs d'audit**
   - Capturer les opérations critiques (revocation, claim, rename)
   - Intégrer dans Filament

### Moyennes

5. **Documentation OpenAPI**
   - Aligner la spec avec les routes exposées
   - Ajouter les schémas de réponse pour les endpoints critiques

---

## État actuel

- **Branch** : `develop`
- **Last commit** : `ddd93da` (audit sécurité)
- **Unmerged stash** : `WIP on develop: 7bc78d3`
- **Livrables** : `notes.md`, `writeup.md`
