"""Codec-specific parameters and commands for the batch example."""

from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Gzip:
    name = "gzip"
    adapter_version = 1
    suffix = ".gz"
    magic = b"\x1f\x8b"
    max_level = 9

    level: int

    def parameters(self):
        return {"level": self.level, "threadMode": "serial", "storeNameAndMtime": False}

    def compress(self, executable: Path, input_path: Path) -> list[str]:
        return [str(executable), "-n", f"-{self.level}", "-c", "--", str(input_path)]

    def decompress(self, executable: Path, archive: Path) -> list[str]:
        return [str(executable), "-d", "-c", "--", str(archive)]


@dataclass(frozen=True)
class Zstandard:
    name = "zstd"
    adapter_version = 1
    suffix = ".zst"
    magic = b"\x28\xb5\x2f\xfd"
    max_level = 19

    level: int

    def parameters(self):
        return {"level": self.level, "threadMode": "single-thread", "frameChecksum": True}

    def compress(self, executable: Path, input_path: Path) -> list[str]:
        return [
            str(executable), "--single-thread", "--check", "--no-progress",
            f"-{self.level}", "-c", "--", str(input_path),
        ]

    def decompress(self, executable: Path, archive: Path) -> list[str]:
        return [str(executable), "-d", "-c", "--", str(archive)]


ADAPTERS = {adapter.name: adapter for adapter in (Gzip, Zstandard)}
