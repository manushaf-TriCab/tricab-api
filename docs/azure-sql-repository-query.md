# Azure SQL repository query guidance

`AzureSqlUserRepository` lives in the PHP API layer, not in TriCab Core.

## Repository flow

1. ProviderCode + TenantIdentifier + SubjectIdentifier
2. `identity.ExternalIdentities` (`IsActive = 1`)
3. `identity.Users`
4. `identity.UserStatuses` (`IsActive = 1`)
5. `identity.UserRoles` (`IsActive = 1`, unexpired `ExpiresAt`)
6. `identity.Roles` (`IsActive = 1`)
7. `identity.RolePermissions` (`IsActive = 1`)
8. `identity.Permissions` (`IsActive = 1`)

## Parameterized lookup (external identity)

```sql
SELECT
    u.[UserId],
    CONVERT(NVARCHAR(36), u.[PublicId]) AS [PublicId],
    u.[EmailAddress],
    u.[DisplayName],
    s.[StatusCode],
    s.[AllowsLogin],
    r.[RoleCode],
    p.[PermissionCode]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s
    ON s.[UserStatusId] = u.[UserStatusId]
   AND s.[IsActive] = 1
LEFT JOIN [identity].[UserRoles] ur
    ON ur.[UserId] = u.[UserId]
   AND ur.[IsActive] = 1
   AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
LEFT JOIN [identity].[Roles] r
    ON r.[RoleId] = ur.[RoleId]
   AND r.[IsActive] = 1
LEFT JOIN [identity].[RolePermissions] rp
    ON rp.[RoleId] = r.[RoleId]
   AND rp.[IsActive] = 1
LEFT JOIN [identity].[Permissions] p
    ON p.[PermissionId] = rp.[PermissionId]
   AND p.[IsActive] = 1
INNER JOIN [identity].[ExternalIdentities] e
    ON e.[UserId] = u.[UserId]
   AND e.[IsActive] = 1
WHERE (e.[ProviderCode] = :provider OR e.[ProviderCode] = :providerAlt)
  AND e.[TenantIdentifier] = :tenant
  AND e.[SubjectIdentifier] = :subject
```

## Notes

- Core `ExternalProvider` normalises `microsoft-entra` → `microsoft_entra`; the repository queries both forms.
- Email lookup uses `NormalizedEmailAddress` (Core email VO already lowercases).
- Roles/permissions are deduplicated in PHP before constructing the Core `User`.
- `AllowsLogin` is returned for diagnostics; authentication eligibility remains a Core `UserStatus` rule.
- Do not store tokens or secrets in identity tables.
- `/api/v1/me` against Azure SQL requires a seeded `ExternalIdentities` row for the caller.
