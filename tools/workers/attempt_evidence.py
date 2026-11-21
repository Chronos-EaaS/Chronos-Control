"""Collect bounded driver evidence from the opened working directory."""

from __future__ import annotations

from contextlib import contextmanager
import os
import stat
from zipfile import ZipFile


MAX_EVIDENCE_BYTES = 16 * 1024 * 1024
MAX_EVIDENCE_FILES = 256
MAX_EVIDENCE_ENTRIES = 512
MAX_PATH_BYTES = 4096


@contextmanager
def open_directory(path, *, parent=None):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_DIRECTORY, dir_fd=parent)
    try:
        yield fd
    finally:
        os.close(fd)


def collect_driver_evidence(bundle: ZipFile, work: int) -> str | None:
    collector = _Collector(bundle)
    try:
        with open_directory("evidence", parent=work) as root:
            try:
                collector.walk(root, "evidence")
            except OSError as error:
                collector.reject(f"Unable to read driver evidence directory: {error.strerror}.")
    except FileNotFoundError:
        return None  # The driver may omit the evidence directory.
    except OSError as error:
        collector.reject(f"Unable to open driver evidence directory: {error.strerror}.")
    return collector.error


class _Collector:
    def __init__(self, bundle: ZipFile):
        self.bundle = bundle
        self.entries = self.files = self.size = 0
        self.error = None

    def reject(self, reason: str) -> None:
        if self.error is None:
            self.error = reason

    def walk(self, directory: int, prefix: str) -> None:
        with os.scandir(directory) as entries:
            names = []
            for entry in entries:
                if self.entries == MAX_EVIDENCE_ENTRIES:
                    self.reject(f"Driver evidence exceeds {MAX_EVIDENCE_ENTRIES} directory entries.")
                    break
                self.entries += 1
                names.append(entry.name)

        for name in sorted(names):
            path = f"{prefix}/{name}"
            if (len(path.encode("utf-8", "surrogatepass")) > MAX_PATH_BYTES
                    or any(char in "\\:" or ord(char) < 32 or 0x7f <= ord(char) <= 0x9f
                           or 0xd800 <= ord(char) <= 0xdfff for char in name)):
                self.reject(f"Invalid driver evidence path {path!r}.")
                continue
            try:
                mode = os.stat(name, dir_fd=directory, follow_symlinks=False).st_mode
                if stat.S_ISDIR(mode):
                    with open_directory(name, parent=directory) as child:
                        self.walk(child, path)
                elif stat.S_ISREG(mode):
                    self.copy(directory, name, path)
                else:
                    self.reject(f"Driver evidence {path!r} must be a regular file or directory.")
            except OSError as error:
                self.reject(f"Unable to read driver evidence {path!r}: {error.strerror}.")

    def copy(self, directory: int, name: str, path: str) -> None:
        if self.files == MAX_EVIDENCE_FILES:
            self.reject(f"Driver evidence exceeds {MAX_EVIDENCE_FILES} files.")
            return
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        with os.fdopen(fd, "rb") as source:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                self.reject(f"Driver evidence {path!r} must be a regular file with one link.")
                return
            if self.size + info.st_size > MAX_EVIDENCE_BYTES:
                self.reject("Driver evidence exceeds 16 MiB total.")
                return
            data = source.read(info.st_size + 1)
            after = os.fstat(fd)
            if (len(data) != info.st_size or after.st_nlink != 1
                    or after.st_mtime_ns != info.st_mtime_ns or after.st_ctime_ns != info.st_ctime_ns):
                self.reject(f"Driver evidence {path!r} changed during collection.")
                return
        self.bundle.writestr(path, data)
        self.files += 1
        self.size += len(data)
