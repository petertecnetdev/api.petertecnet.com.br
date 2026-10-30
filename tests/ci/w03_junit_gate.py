"""Gate de integridade da suíte W03 no relatório JUnit oficial.

Uso: python3 tests/ci/w03_junit_gate.py caminho/para/phpunit-w03.xml
"""

from __future__ import annotations

import sys
from pathlib import Path
from xml.etree import ElementTree

TEST_CLASS = "ApplicationAdminTenantBoundaryW03Test"
ROOT_BOUNDARY_TEST = "test_administrador_principal_da_cutinapp_nao_e_root_do_ecossistema"
MIN_CASES = 18


def validar_junit(path: Path) -> int:
    """Valida integridade, cobertura mínima e ausência de falhas da suíte W03."""
    try:
        root = ElementTree.parse(path).getroot()
    except (OSError, ElementTree.ParseError) as exc:
        raise ValueError(f"JUnit W03 ausente ou inválido: {exc}") from exc

    if root.tag not in {"testsuite", "testsuites"}:
        raise ValueError("JUnit W03 não possui elemento raiz de suíte de testes.")

    cases = root.findall(".//testcase")
    if len(cases) < MIN_CASES:
        raise ValueError(f"Suíte W03 incompleta: {len(cases)} testes; mínimo {MIN_CASES}.")

    seen: set[tuple[str, str]] = set()
    names: set[str] = set()
    for case in cases:
        classname = case.get("classname") or case.get("class") or ""
        classname = classname.replace("\\", ".").split(".")[-1]
        name = case.get("name") or ""
        if classname != TEST_CLASS or not name:
            raise ValueError(f"Caso JUnit fora da suíte W03: {classname}.{name}")
        identity = (classname, name)
        if identity in seen:
            raise ValueError(f"Caso JUnit W03 duplicado: {name}")
        seen.add(identity)
        names.add(name)

    if ROOT_BOUNDARY_TEST not in names:
        raise ValueError("Teste P0 root global vs owner local ausente do JUnit W03.")

    if any(next(root.iter(tag), None) is not None for tag in ("failure", "error", "skipped")):
        raise ValueError("JUnit W03 registra falha, erro ou teste ignorado.")

    # Conferir contagens declaradas pelo PHPUnit, sem depender de atributos opcionais.
    for suite in root.iter():
        if suite.tag not in {"testsuite", "testsuites"}:
            continue
        for attr in ("failures", "errors", "skipped"):
            declared = suite.get(attr)
            if declared is not None and int(declared) != 0:
                raise ValueError(f"JUnit W03 declara {attr}={declared}.")
        declared_tests = suite.get("tests")
        if declared_tests is not None:
            actual = len(suite.findall(".//testcase"))
            if int(declared_tests) != actual:
                raise ValueError(f"JUnit W03 inconsistente: tests={declared_tests}, casos={actual}.")

    return len(cases)


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("Uso: w03_junit_gate.py caminho/phpunit-w03.xml", file=sys.stderr)
        return 2
    try:
        count = validar_junit(Path(argv[1]))
    except (ValueError, TypeError) as exc:
        print(f"::error::{exc}", file=sys.stderr)
        return 1
    print(f"PHPUnit oficial W03: {count} testes únicos, sem falhas/erros/ignorados.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
