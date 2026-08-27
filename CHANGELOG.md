# Changelog

All notable changes to `coolms/rql` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

⚠️ Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## Unreleased

Contributor documentation only: `CONTRIBUTING.md`, describing the Tuesday
release train, the deprecation window, and how this package's version number
relates to the CoolMS platform packages.

No code changed, so **this will not be released on its own.** It rides out with
the next change that is worth a version number -- publishing an empty patch to
ship a documentation file would contradict the policy the file describes.

## 1.0.0 - 2026-08-13

First release. The RQL domain layer: parser, AST nodes, immutable value objects,
an in-memory filter, and the interfaces a translator implements. Zero
dependencies.
