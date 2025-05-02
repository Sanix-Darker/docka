# DOCKA

Build, test and run any public repo that ships a `docker-compose.yml` or `Dockerfile` in the project tree.

## DISCLAIMER

This is for tests purposes only...
I made it to run containers only after 1h...

## PREREQUISITES

* Linux (or WSL) with **Docker ≥ 24** and **docker compose** plugin
* PHP 8.1+ with `proc_open`, `shell_exec` enabled
* `git`

## RUN LOCALLY

```bash
git clone https://github.com/sanix-darker/docka.git
cd docka
php -S 0.0.0.0:7711 -t public
```

*NOTE:* I tried the DinD (Docker In Docker) version... but it's not working yet as i wish...

*NOTE2:* Consider the config.php (if you want to deploy docka for yourself...)

## so, HOW I MADE IT WORKS INSIDE

1. **index.php** renders the single-page form.
2. The JS posts to **build.php**.
3. `build.php` spins up a `Sandbox` object:
   * clones the repo → `builds/<id>/` (because i needed the version of the project before the build)
   * locates Compose or Dockerfile
   * uses **DockerManager** to build & run it...
   * captures logs and published ports (so that it can be access by anyone...)

4. The JSON response is rendered back into the `<pre>` block with live links.
