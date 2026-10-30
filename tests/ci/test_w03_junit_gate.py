"""Testes sem dependências externas do verificador de evidência W03."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
from xml.etree import ElementTree as ET

from w03_junit_gate import MIN_CASES, ROOT_BOUNDARY_TEST, TEST_CLASS, validar_junit


class W03JUnitGateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / "w03.xml"

    def xml(self, *, count=MIN_CASES, p0=True, duplicate=False, classname=TEST_CLASS,
            problem=None, declared=None) -> None:
        root = ET.Element("testsuites", tests=str(count if declared is None else declared))
        suite = ET.SubElement(root, "testsuite", tests=str(count if declared is None else declared))
        for index in range(count):
            name = ROOT_BOUNDARY_TEST if index == 0 and p0 else f"test_cenario_{index:02d}"
            if duplicate and index == count - 1:
                name = ROOT_BOUNDARY_TEST
            case = ET.SubElement(suite, "testcase", classname=classname, name=name)
            if problem and index == 1:
                ET.SubElement(case, problem)
        self.path.write_bytes(ET.tostring(root, encoding="utf-8"))

    def test_sucesso_com_18_casos_e_p0(self) -> None:
        self.xml()
        self.assertEqual(validar_junit(self.path), 18)

    def test_recusa_suite_incompleta(self) -> None:
        self.xml(count=17)
        with self.assertRaisesRegex(ValueError, "incompleta"):
            validar_junit(self.path)

    def test_recusa_remocao_da_regressao_root(self) -> None:
        self.xml(p0=False)
        with self.assertRaisesRegex(ValueError, "root global"):
            validar_junit(self.path)

    def test_recusa_duplicacao_para_simular_cobertura(self) -> None:
        self.xml(duplicate=True)
        with self.assertRaisesRegex(ValueError, "duplicado"):
            validar_junit(self.path)

    def test_recusa_caso_de_outra_suite(self) -> None:
        self.xml(classname="OutraSuiteTest")
        with self.assertRaisesRegex(ValueError, "fora da suíte"):
            validar_junit(self.path)

    def test_recusa_teste_ignorado(self) -> None:
        self.xml(problem="skipped")
        with self.assertRaisesRegex(ValueError, "ignorado"):
            validar_junit(self.path)

    def test_recusa_falha_de_assert(self) -> None:
        self.xml(problem="failure")
        with self.assertRaisesRegex(ValueError, "falha"):
            validar_junit(self.path)

    def test_recusa_erro_de_execucao(self) -> None:
        self.xml(problem="error")
        with self.assertRaisesRegex(ValueError, "erro"):
            validar_junit(self.path)

    def test_recusa_contagem_declarada_inconsistente(self) -> None:
        self.xml(declared=19)
        with self.assertRaisesRegex(ValueError, "inconsistente"):
            validar_junit(self.path)

    def test_recusa_xml_invalido(self) -> None:
        self.path.write_text("<testsuites><testcase>")
        with self.assertRaisesRegex(ValueError, "inválido"):
            validar_junit(self.path)

    def test_recusa_arquivo_ausente(self) -> None:
        with self.assertRaisesRegex(ValueError, "ausente"):
            validar_junit(self.path)


if __name__ == "__main__":
    unittest.main()
