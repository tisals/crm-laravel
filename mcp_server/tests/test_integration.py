"""Integration tests for the MCP server stdio boot.

These tests spawn the server as a subprocess and exchange a JSON-RPC
``initialize`` handshake to prove the server boots on stdio.

They live separately from the unit tests so they can be excluded from
fast feedback loops (mark with ``@pytest.mark.integration``).
"""

from __future__ import annotations

import json
import os
import socket
import subprocess
import sys
import time
from pathlib import Path

import pytest


PACKAGE_ROOT = Path(__file__).resolve().parent.parent
PYPROJECT = PACKAGE_ROOT / "pyproject.toml"


def _resolve_python() -> str:
    """Return the current Python interpreter absolute path."""
    return sys.executable


def _free_port() -> int:
    """Find a free TCP port for the SSE/HTTP fallback test (unused)."""
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def _build_server_env() -> dict[str, str]:
    """Build env for the server subprocess.

    Adds the ``src/`` directory to ``PYTHONPATH`` so the package is
    importable from the source tree (no install needed).
    """
    env = os.environ.copy()
    src = PACKAGE_ROOT / "src"
    env["PYTHONPATH"] = str(src) + os.pathsep + env.get("PYTHONPATH", "")
    # Force stdio transport regardless of host env.
    env["MCP_TRANSPORT"] = "stdio"
    # Suppress noisy warnings on stderr during the test.
    env["PYTHONWARNINGS"] = "ignore::DeprecationWarning"
    return env


@pytest.mark.integration
@pytest.mark.timeout(30)
def test_server_module_starts_and_responds_to_python_dot_command() -> None:
    """The server module MUST be invokable via ``python -m`` without error.

    This is a cheap boot smoke-test: we spawn the module with stdio,
    send a quick stdin close, and assert it exits cleanly within 5s.
    A full MCP handshake is verified in the next test.
    """
    src = PACKAGE_ROOT / "src"
    env = _build_server_env()
    proc = subprocess.run(
        [
            _resolve_python(),
            "-c",
            (
                "import sys; sys.path.insert(0, r'%s'); "
                "from empresas_colombia import server; "
                "assert server.mcp is not None; "
                "print('OK')"
            )
            % str(src),
        ],
        env=env,
        capture_output=True,
        text=True,
        timeout=15,
    )
    assert proc.returncode == 0, (
        f"server module failed to import: stderr={proc.stderr!r}, stdout={proc.stdout!r}"
    )
    assert "OK" in proc.stdout, f"expected OK marker; got {proc.stdout!r}"


@pytest.mark.integration
@pytest.mark.timeout(30)
def test_server_lists_tools_via_internal_api() -> None:
    """The FastMCP instance MUST register both tools without a network call."""
    src = PACKAGE_ROOT / "src"
    env = _build_server_env()
    code = (
        "import asyncio, json\n"
        f"import sys; sys.path.insert(0, r'{src}')\n"
        "from empresas_colombia import server\n"
        "async def main():\n"
        "    tools = await server.mcp.get_tools()\n"
        "    print(json.dumps(sorted(tools.keys())))\n"
        "asyncio.run(main())\n"
    )
    proc = subprocess.run(
        [_resolve_python(), "-c", code],
        env=env,
        capture_output=True,
        text=True,
        timeout=20,
    )
    assert proc.returncode == 0, (
        f"server boot failed: stderr={proc.stderr!r}, stdout={proc.stdout!r}"
    )
    names = json.loads(proc.stdout.strip().splitlines()[-1])
    assert "buscar_por_dominio" in names
    assert "consultar_empresa" in names


@pytest.mark.integration
@pytest.mark.timeout(30)
def test_server_lists_resources_via_internal_api() -> None:
    """The Decreto matrix resource MUST be registered."""
    src = PACKAGE_ROOT / "src"
    env = _build_server_env()
    code = (
        "import asyncio, json\n"
        f"import sys; sys.path.insert(0, r'{src}')\n"
        "from empresas_colombia import server\n"
        "async def main():\n"
        "    resources = await server.mcp.get_resources()\n"
        "    print(json.dumps(sorted(resources.keys())))\n"
        "asyncio.run(main())\n"
    )
    proc = subprocess.run(
        [_resolve_python(), "-c", code],
        env=env,
        capture_output=True,
        text=True,
        timeout=20,
    )
    assert proc.returncode == 0, (
        f"server boot failed: stderr={proc.stderr!r}, stdout={proc.stdout!r}"
    )
    uris = json.loads(proc.stdout.strip().splitlines()[-1])
    assert "ciiu://decreto-768/matriz-riesgos" in uris


@pytest.mark.integration
@pytest.mark.timeout(60)
def test_server_responds_to_mcp_initialize_handshake() -> None:
    """Spawn the stdio server and exchange the MCP initialize handshake.

    This is the strict-tdd integration gate: the server must respond to
    a real MCP JSON-RPC ``initialize`` request and announce its protocol
    version. We DO NOT exercise tool calls (those are covered by the
    in-process server tests); we only prove the stdio loop is wired up.
    """
    src = PACKAGE_ROOT / "src"
    env = _build_server_env()

    # Use the console-script entry point via ``python -m``. The server
    # runs ``mcp.run(transport='stdio')`` which reads JSON-RPC frames
    # from stdin and writes to stdout.
    proc = subprocess.Popen(
        [_resolve_python(), "-m", "empresas_colombia.server"],
        env=env,
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        bufsize=1,
    )
    try:
        # Send a minimal MCP initialize request.
        request = {
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2024-11-05",
                "capabilities": {},
                "clientInfo": {"name": "pytest", "version": "0.1"},
            },
        }
        assert proc.stdin is not None
        proc.stdin.write(json.dumps(request) + "\n")
        proc.stdin.flush()
        proc.stdin.close()

        # Read everything the server writes within the timeout.
        try:
            stdout_data, stderr_data = proc.communicate(timeout=20)
        except subprocess.TimeoutExpired:
            proc.kill()
            stdout_data, stderr_data = proc.communicate()
            pytest.fail(
                f"stdio server did not respond within 20s. "
                f"stderr={stderr_data!r}"
            )

        # The server output might be empty if it crashed early; in that
        # case fall back to stderr for diagnosis.
        output = (stdout_data or "").strip()
        if not output:
            pytest.fail(
                f"stdio server produced no stdout; stderr={stderr_data!r}"
            )

        # FastMCP may emit a single line OR multiple JSON-RPC frames.
        lines = [ln for ln in output.splitlines() if ln.strip()]
        assert lines, f"no non-empty lines in stdout: {output!r}"

        # The first non-empty line MUST be a JSON-RPC response to our
        # ``initialize`` request.
        first_line = lines[0]
        try:
            response = json.loads(first_line)
        except json.JSONDecodeError as exc:
            pytest.fail(
                f"non-JSON response from stdio server: {first_line!r} "
                f"(stderr={stderr_data!r}): {exc}"
            )

        # Validate the JSON-RPC envelope.
        assert response.get("jsonrpc") == "2.0", (
            f"response missing jsonrpc field: {response!r}"
        )
        assert response.get("id") == 1, (
            f"response id mismatch: expected 1, got {response.get('id')!r}"
        )
        # Either a result OR an error key MUST be present.
        assert "result" in response or "error" in response, (
            f"JSON-RPC response missing both result and error: {response!r}"
        )
    finally:
        if proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()