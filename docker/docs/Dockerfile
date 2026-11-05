# syntax=docker/dockerfile:1
# Builds the documentation site (make docs-site) with Zensical, the MkDocs-compatible successor of Material for MkDocs.
FROM python:3.13-slim

COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app && useradd -m -u ${UID} -g app app

USER app
WORKDIR /app
