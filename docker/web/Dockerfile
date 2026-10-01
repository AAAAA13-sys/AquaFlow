# AquaFlow presentation tier (Tier 1) - nginx reverse proxy in front of Laravel.
# Terminates client connections, adds security headers and forwards to the app.
FROM nginx:1.27-alpine

RUN rm -f /etc/nginx/conf.d/default.conf

COPY docker/web/nginx.conf /etc/nginx/conf.d/aquaflow.conf

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1
