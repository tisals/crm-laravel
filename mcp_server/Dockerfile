# Dockerfile for the empresas-colombia MCP server.
#
# Default transport: stdio (driven by ``python -m empresas_colombia.server``).
# Override with ``MCP_TRANSPORT=http`` to bind uvicorn on port 8000 for
# HTTP/SSE production deployments.

FROM python:3.11-slim AS builder

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /build

# Install build deps + the package + its runtime deps.
COPY requirements.txt ./
RUN pip install --upgrade pip && \
    pip install -r requirements.txt

COPY pyproject.toml ./
COPY src ./src
RUN pip install --no-deps .


# --- Runtime stage ----------------------------------------------------------

FROM python:3.11-slim AS runtime

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    MCP_TRANSPORT=stdio

# Copy the installed package and resources from the builder.
COPY --from=builder /usr/local/lib/python3.11/site-packages /usr/local/lib/python3.11/site-packages
COPY --from=builder /usr/local/bin /usr/local/bin
COPY --from=builder /build/src/empresas_colombia/resources /app/resources

WORKDIR /app

# Default: stdio MCP. Override MCP_TRANSPORT=http for HTTP/SSE on :8000.
CMD ["python", "-m", "empresas_colombia.server"]

EXPOSE 8000