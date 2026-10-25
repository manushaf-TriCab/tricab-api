# TriCab App Service package

Build the combined frontend/API package from `tricab-api`:

```powershell
.\scripts\build-app-service-release.ps1
```

The default build targets the existing `tc-web/core` slot hostname. Override it
with `-WebOrigin https://example.test` when creating a package for another host.

Configure the Linux PHP App Service startup command before deploying:

```text
cp /home/site/wwwroot/default /etc/nginx/sites-available/default && service nginx reload
```

The generated ZIP is ready to deploy without App Service build automation. It
contains the Vite output at the web root, the Slim application under `app/`,
public content media at site-root `/media/` (one copy; PHP availability checks
reach it as `../media` from `app/`), and an Azure-specific front controller
under `api/`.
