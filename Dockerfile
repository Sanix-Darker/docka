# ─────────────────────────────────────────────────────────────────────────────
# Docka - Docker Sandbox Runner
# Multi-stage build for security and smaller image size
# ─────────────────────────────────────────────────────────────────────────────

FROM php:8.4-fpm AS base

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    git \
    curl \
    ca-certificates \
    tzdata \
    tini \
    iptables \
    iproute2 \
    xz-utils \
    fuse-overlayfs \
    && rm -rf /var/lib/apt/lists/* \
    && rm /etc/nginx/sites-enabled/default

# Install PHP extensions
RUN docker-php-ext-install sockets pcntl

# Install Docker CLI
ENV DOCKER_VERSION=24.0.7
RUN curl -fsSL "https://download.docker.com/linux/static/stable/x86_64/docker-${DOCKER_VERSION}.tgz" \
    | tar -xz -C /usr/local/bin --strip-components=1 \
    && chmod +x /usr/local/bin/docker*

# Install Docker Compose plugin
RUN mkdir -p /usr/local/lib/docker/cli-plugins \
    && curl -fsSL "https://github.com/docker/compose/releases/download/v2.24.0/docker-compose-linux-x86_64" \
       -o /usr/local/lib/docker/cli-plugins/docker-compose \
    && chmod +x /usr/local/lib/docker/cli-plugins/docker-compose

# Create directories for Docker
RUN mkdir -p /var/lib/docker /run/docker
VOLUME /var/lib/docker

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better caching
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy application code
COPY . .

# Run composer scripts after copying code
RUN composer dump-autoload --optimize

# Create necessary directories
RUN mkdir -p builds logs \
    && chown -R www-data:www-data /var/www/html

# Configure PHP-FPM
RUN sed -ri 's!^listen = .*!listen = 127.0.0.1:9000!' /usr/local/etc/php-fpm.d/zz-docker.conf

# Configure Nginx
RUN printf '%s\n' \
    'server {' \
    '    listen 80;' \
    '    root /var/www/html/public;' \
    '    index index.php index.html;' \
    '' \
    '    # Security headers' \
    '    add_header X-Frame-Options "SAMEORIGIN" always;' \
    '    add_header X-Content-Type-Options "nosniff" always;' \
    '    add_header X-XSS-Protection "1; mode=block" always;' \
    '    add_header Referrer-Policy "strict-origin-when-cross-origin" always;' \
    '' \
    '    # Disable access to hidden files' \
    '    location ~ /\. {' \
    '        deny all;' \
    '        access_log off;' \
    '        log_not_found off;' \
    '    }' \
    '' \
    '    # Disable access to sensitive files' \
    '    location ~* /(composer\.(json|lock)|\.git|\.env|config\.php) {' \
    '        deny all;' \
    '    }' \
    '' \
    '    location / {' \
    '        try_files $uri $uri/ /index.php?$args;' \
    '    }' \
    '' \
    '    location ~ \.php$ {' \
    '        include fastcgi_params;' \
    '        fastcgi_pass 127.0.0.1:9000;' \
    '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' \
    '        fastcgi_intercept_errors on;' \
    '        fastcgi_buffer_size 16k;' \
    '        fastcgi_buffers 4 16k;' \
    '    }' \
    '' \
    '    # Static file caching' \
    '    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2)$ {' \
    '        expires 30d;' \
    '        add_header Cache-Control "public, no-transform";' \
    '    }' \
    '' \
    '    client_max_body_size 32m;' \
    '    client_body_timeout 60s;' \
    '}' > /etc/nginx/conf.d/default.conf

# Use tini as init
ENTRYPOINT ["/usr/bin/tini", "--"]

# Expose port
EXPOSE 81

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/index.php || exit 1

# Start services
CMD ["/bin/bash", "-eu", "-o", "pipefail", "-c", "\
    modprobe overlay 2>/dev/null || true; \
    \
    echo '🐳 Starting Docker daemon...'; \
    dockerd \
        --host=unix:///var/run/docker.sock \
        --storage-driver=overlay2 \
        --log-level=warn \
        2>&1 & \
    \
    for i in $(seq 1 30); do \
        docker info >/dev/null 2>&1 && break; \
        echo '⏳ Waiting for Docker daemon...'; \
        sleep 1; \
    done; \
    \
    if ! docker info >/dev/null 2>&1; then \
        echo '❌ Docker daemon failed to start'; \
        exit 1; \
    fi; \
    \
    echo '✅ Docker daemon ready'; \
    echo '🚀 Starting PHP-FPM and Nginx...'; \
    \
    php-fpm -D; \
    exec nginx -g 'daemon off;' \
"]
