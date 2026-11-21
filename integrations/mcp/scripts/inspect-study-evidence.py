"""Verify the raw study records in a downloaded Chronos attempt ZIP."""

from hashlib import sha256
import json
from pathlib import PurePosixPath
import sys
from zipfile import ZipFile


def inspect(archive, pilot):
    names = archive.namelist()
    assert len(names) == len(set(names)), "Duplicate ZIP entries"

    def data(path):
        return json.loads(archive.read(path))

    request = data("evidence/chronos.json")
    assert request["specification"]["setup"] == {}, "The worker's frozen setup must be a JSON object"
    stdout = archive.read("stdout.json")
    stderr = archive.read("stderr.log").decode("utf-8")
    result = json.loads(stdout) if stdout else None

    if pilot in {"gzip", "zstd"}:
        path, = (name for name in names if name.startswith("evidence/study/attempts/")
                 and name.endswith("/result.json"))
        detail = data(path)
        assert detail["codec"] == pilot
        assert archive.read("evidence/study/plan.sha256").decode().strip() == detail["planSha256"]
        assert sha256(archive.read("evidence/study/plan.json")).hexdigest() == detail["planSha256"]
        assert data(str(PurePosixPath(path).with_name("attempt.json")))["attemptId"] == detail["attemptId"]
        input_data = archive.read("evidence/study/input.bin")
        if result is None:
            assert detail["status"] == "failed" and detail["error"] and "Benchmark driver:" in stderr
        else:
            assert detail["status"] == "succeeded" and len(detail["warmups"]) == 1
            assert result["compressionElapsedNs"] == detail["measurement"]["compression"]["elapsedNs"] > 0
            assert result["inputBytes"] == len(input_data)
            assert result["restoredSha256"] == sha256(input_data).hexdigest()
            compressed = archive.read(str(PurePosixPath(path).parent / detail["measurement"]["output"]["path"]))
            assert result["compressedBytes"] == len(compressed)
    else:
        root = "evidence/study/"
        study = data(root + "run.json")
        repetition, = study["repetitions"]
        attempt, = repetition["attempts"]
        path = root + attempt["evidence"]
        detail = data(path)
        assert study["status"] == detail["status"] == "succeeded"
        assert detail["eligible"] and not detail["teardownErrors"]
        assert detail["datasetBefore"] == detail["datasetAfter"]
        dataset = json.dumps(detail["datasetBefore"], sort_keys=True, separators=(",", ":")).encode()
        assert result["datasetSha256"] == sha256(dataset).hexdigest()
        assert result["events"] > 0 and result["sysbenchElapsedSeconds"] > 0
        measurement_stdout = PurePosixPath(path).parent / detail["measurement"]["stdout"]
        assert b"SQL statistics:" in archive.read(str(measurement_stdout))
        assert any(name.endswith("-warmup.stdout.log") for name in names)

    return {"repetitionId": request["repetitionId"], "attemptId": request["attemptId"],
            "system": request["specification"]["system"], "binding": request["specification"]["binding"],
            "specificationSha256": request["specification"]["sha256"], "result": result,
            "stderr": stderr, "studyRecord": path, "entryCount": len(names)}


if __name__ == "__main__":
    with ZipFile(sys.argv[1]) as bundle:
        print(json.dumps(inspect(bundle, sys.argv[2])))
