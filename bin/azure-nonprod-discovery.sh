#!/usr/bin/env bash
# Read-only Azure discovery for TriCab non-production targets.
# Run locally with a working `az login` session (outside Cursor sandbox if needed).
set -euo pipefail

echo "=== Safety ==="
az account show --query '{subscription:name,id:id,tenant:tenantId,user:user.name}' -o json

echo
echo "=== Resource groups ==="
az group list --query "[].{name:name,location:location}" -o table

echo
echo "=== App Service plans ==="
az appservice plan list --query "[].{name:name,rg:resourceGroup,sku:sku.name,tier:sku.tier,location:location}" -o table

echo
echo "=== Web apps ==="
az webapp list --query "[].{name:name,rg:resourceGroup,state:state,host:defaultHostName,kind:kind}" -o table

echo
echo "=== Slots (non-prod candidates) ==="
for app in $(az webapp list --query "[].name" -o tsv); do
  rg=$(az webapp list --query "[?name=='$app'].resourceGroup | [0]" -o tsv)
  slots=$(az webapp deployment slot list -g "$rg" -n "$app" --query "[].name" -o tsv 2>/dev/null || true)
  if [[ -n "${slots:-}" ]]; then
    echo "$app ($rg): $slots"
  fi
done

echo
echo "=== Static Web Apps ==="
az staticwebapp list --query "[].{name:name,rg:resourceGroup,host:defaultHostname}" -o table 2>/dev/null || true

echo
echo "=== Custom domain hints (manual portal check) ==="
echo "Historical: uat.tricab.com, dev.tricab.com (may be Site Disabled)"
echo "Test refs: tricab-web-staging.azurewebsites.net, tricab-api-staging.azurewebsites.net"
