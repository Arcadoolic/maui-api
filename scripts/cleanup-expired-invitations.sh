#!/usr/bin/env bash
# Script de nettoyage des invitations expirées pour l'API maui

php /home/kali/git/maui-api/artisan db:table invitations --where="expires_at < NOW() AND revoked_at IS NULL"

php /home/kali/git/maui-api/artisan tinker --execute="
\$expired = App\Models\Invitation::where('expires_at', '<', now())->whereNull('revoked_at')->get();
\$expired->each(function(\$invite) {
    \$invite->update(['revoked_at' => now(), 'revoked_reason' => 'auto-expired']);
});
echo 'Nettoyage terminé : ' . \$expired->count() . ' invitations expirées révoquées.';
"
