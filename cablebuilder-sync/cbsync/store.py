"""Where archived bytes live.

Two backends behind one small interface: a local folder for development and
tests, and an Azure Blob container for the real thing. Everything above this
module speaks in blob names ("designs/KL39X-CAXN%2F3C2.5BK/a0v0/...") and never
knows which backend it is talking to.
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Iterator, Protocol


class Store(Protocol):
    def put(self, name: str, data: bytes, metadata: dict[str, str] | None = None) -> None: ...
    def get(self, name: str) -> bytes | None: ...
    def exists(self, name: str) -> bool: ...
    def list(self, prefix: str = "") -> Iterator[str]: ...


class LocalStore:
    """A folder standing in for a blob container.

    Blob names are case-sensitive and Windows file names are not, so two design
    codes differing only in case would share a file here. That is acceptable for
    development; the Azure backend has no such limit.
    """

    def __init__(self, root: str | Path) -> None:
        self.root = Path(root)
        self.root.mkdir(parents=True, exist_ok=True)

    def _path(self, name: str) -> Path:
        return self.root.joinpath(*name.split("/"))

    def put(self, name: str, data: bytes, metadata: dict[str, str] | None = None) -> None:
        p = self._path(name)
        p.parent.mkdir(parents=True, exist_ok=True)
        tmp = p.with_name(p.name + ".tmp")
        tmp.write_bytes(data)
        tmp.replace(p)  # a crash mid-write never leaves a truncated blob behind

    def get(self, name: str) -> bytes | None:
        p = self._path(name)
        return p.read_bytes() if p.exists() else None

    def exists(self, name: str) -> bool:
        return self._path(name).exists()

    def list(self, prefix: str = "") -> Iterator[str]:
        for p in sorted(self.root.rglob("*")):
            if p.is_file() and not p.name.endswith(".tmp"):
                name = p.relative_to(self.root).as_posix()
                if name.startswith(prefix):
                    yield name


class BlobStore:
    """An Azure Blob container.

    Auth, in order: BLOB_CONNECTION_STRING, the app setting the tc-web slots
    already carry (BLOB_AUTH_MODE=connection-string), or
    AZURE_STORAGE_CONNECTION_STRING; else the account URL with
    DefaultAzureCredential (managed identity in Azure, `az login` locally).
    """

    def __init__(self, container: str, account_url: str | None = None) -> None:
        from azure.storage.blob import ContainerClient

        conn = (os.environ.get("BLOB_CONNECTION_STRING")
                or os.environ.get("AZURE_STORAGE_CONNECTION_STRING"))
        if conn:
            self.c = ContainerClient.from_connection_string(conn, container)
        elif account_url:
            from azure.identity import DefaultAzureCredential

            self.c = ContainerClient(account_url, container, credential=DefaultAzureCredential())
        else:
            raise SystemExit(
                "Blob store needs AZURE_STORAGE_CONNECTION_STRING or --account-url."
            )
        if not self.c.exists():
            self.c.create_container()

    def put(self, name: str, data: bytes, metadata: dict[str, str] | None = None) -> None:
        self.c.upload_blob(name, data, overwrite=True, metadata=metadata)

    def get(self, name: str) -> bytes | None:
        from azure.core.exceptions import ResourceNotFoundError

        try:
            return self.c.download_blob(name).readall()
        except ResourceNotFoundError:
            return None

    def exists(self, name: str) -> bool:
        return self.c.get_blob_client(name).exists()

    def list(self, prefix: str = "") -> Iterator[str]:
        for b in self.c.list_blobs(name_starts_with=prefix or None):
            yield b.name


def open_store(target: str, account_url: str | None = None) -> Store:
    """`blob:<container>` for Azure, anything else is a local folder."""
    if target.startswith("blob:"):
        return BlobStore(target[5:], account_url)
    return LocalStore(target)
