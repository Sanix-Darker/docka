FROM php:8.4-fpm

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        nginx git curl ca-certificates tzdata tini \
        iptables iproute2 xz-utils fuse-overlayfs && \
    rm /etc/nginx/sites-enabled/default && \
    rm -rf /var/lib/apt/lists/*

ENTRYPOINT ["/usr/bin/tini", "--"]

ENV DOCKER_VERSION=24.0.7
RUN curl -fsSL https://download.docker.com/linux/static/stable/x86_64/docker-${DOCKER_VERSION}.tgz \
      | tar -xz -C /usr/local/bin --strip-components=1 && \
    chmod +x /usr/local/bin/docker*

RUN mkdir /var/lib/docker /run/docker
VOLUME /var/lib/docker

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction
COPY . .

# – tell PHP-FPM to listen on a UNIX socket for better performance
RUN sed -ri 's!^listen = .*!listen = /run/php-fpm.sock!' \
        /usr/local/etc/php-fpm.d/zz-docker.conf

# Maybe i should have an external proper file fot this...
# – minimal Nginx vhost (served from /public)
RUN printf '%s\n' \
  'server {' \
  '    listen 80;' \
  '    root /var/www/html/public;' \
  '    index index.php index.html;' \
  '    location / {' \
  '        try_files $uri $uri/ /index.php?$args;' \
  '    }' \
  '    location ~ \.php$ {' \
  '        include fastcgi_params;' \
  '        fastcgi_pass 127.0.0.1:9000;' \
  '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' \
  '    }' \
  '    client_max_body_size 32m;' \
  '}' > /etc/nginx/conf.d/default.conf

# – make sure www-data owns the web root
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# 6) Entrypoint: start dockerd → wait → PHP-FPM → Nginx -----------------------
CMD ["/bin/bash", "-eu", "-o", "pipefail", "-c", "\
      modprobe overlay || true; \
      dockerd --host=unix:///var/run/docker.sock --storage-driver=overlay2 & \
      for i in {1..30}; do \
          docker info >/dev/null 2>&1 && break; \
          echo '⌛ Waiting for inner Docker daemon…'; sleep 1; \
      done; \
      if ! docker info >/dev/null 2>&1; then \
          echo '❌ dockerd failed to start'; exit 1; \
      fi; \
      echo '✅ Docker daemon up, launching services'; \
      php-fpm -D; \
      exec nginx -g 'daemon off;' \
"]
