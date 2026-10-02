#!/usr/bin/env bash
# توليد شهادات TLS للاختبار/البدء السريع — للإنتاج استخدم Let's Encrypt.
#
#   1) ضع الشهادات الحقيقية في ./certs/ (fullchain.pem + privkey.pem)
#      أو
#   2) شغّل هذا السكربت لتوليد شهادة ذاتية موقعة (للاستخدام الداخلي فقط):
#        ./scripts/bootstrap-certs.sh
#   3) ثم: docker compose up -d

set -euo pipefail

CERT_DIR="$(cd "$(dirname "$0")/.." && pwd)/certs"

if [[ -f "$CERT_DIR/fullchain.pem" && -f "$CERT_DIR/privkey.pem" ]]; then
    echo "✔ Certificates already present in $CERT_DIR — nothing to do."
    exit 0
fi

mkdir -p "$CERT_DIR"

echo "⚠ Generating SELF-SIGNED certificates (development only)."
echo "  For production, obtain real certificates from Let's Encrypt:"
echo "    apt install certbot && certbot certonly --standalone -d api.yourdomain.com"

DOMAIN="${1:-lms.local}"

openssl req -x509 -nodes -newkey rsa:2048 -days 365 \
    -keyout "$CERT_DIR/privkey.pem" \
    -out "$CERT_DIR/fullchain.pem" \
    -subj "/CN=$DOMAIN" \
    -addext "subjectAltName=DNS:$DOMAIN,DNS:localhost,IP:127.0.0.1" 2>/dev/null

chmod 600 "$CERT_DIR/privkey.pem"

echo "✔ Self-signed certificates created in $CERT_DIR"
echo "  Next: docker compose up -d"
