import importlib.util
import math
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKER = ROOT / "tools" / "python_worker.py"
SPEC = importlib.util.spec_from_file_location("agent_python_worker", WORKER)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)

class PythonCalculateTests(unittest.TestCase):
    def test_human_math_notation(self):
        cases = [
            ("2 × (3 + 4)", 14),
            ("2^10 + √(81) + π", 1024 + 9 + math.pi),
            ("2pi", 2 * math.pi),
            ("(5 + 3)²", 64),
            ("3!", 6),
            ("25%", .25),
        ]
        for expr, expected in cases:
            with self.subTest(expr=expr):
                self.assertAlmostEqual(float(MODULE.calculate({"expression": expr})["result"]), expected, places=9)

    def test_symbolic_algebra_and_calculus(self):
        self.assertEqual(MODULE.calculate({"expression":"(x^2-1)/(x-1)", "mode":"simplify"})["result"], "x + 1")
        self.assertEqual(MODULE.calculate({"expression":"x^2=4", "mode":"solve"})["result"], [-2.0, 2.0])
        self.assertEqual(MODULE.calculate({"expression":"sin^2(x)", "mode":"derivative"})["result"], "2*sin(x)*cos(x)")
        self.assertEqual(MODULE.calculate({"expression":"sin(x)/x", "mode":"limit", "point":0})["result"], 1.0)
        self.assertEqual(MODULE.calculate({"expression":"sin(x)/x", "mode":"limit", "point":"0"})["result"], 1.0)
        self.assertEqual(MODULE.calculate({"expression":"sin(x)^2", "mode":"derivative"})["result"], "2*sin(x)*cos(x)")
        self.assertEqual(MODULE.calculate({"expression":"2*x+cos(x)", "mode":"integrate"})["result"], "x**2 + sin(x) + C")

    def test_matrix(self):
        result = MODULE.calculate({"expression":"0", "mode":"matrix", "matrix":[[1,2],[3,4]], "matrix_operation":"determinant"})
        self.assertEqual(result["result"], -2)


    def test_unit_conversion_is_not_duplicate_calculator_capability(self):
        with self.assertRaises(ValueError):
            MODULE.calculate({"mode":"convert", "conversion_type":"unit", "value":1, "from_unit":"m", "to_unit":"km"})
        astronomy = MODULE.calculate({"mode":"convert", "conversion_type":"astronomy", "astronomy_operation":"distance", "value":1, "from_unit":"au", "to_unit":"km"})
        self.assertAlmostEqual(astronomy["result"]["converted"], 149597870.7, places=3)

    def test_rejects_prose_and_code_execution(self):
        for expr in ["Calculate 2 plus 3", "__import__('os').system('id')", "x.__class__", "open('secret')"]:
            with self.subTest(expr=expr), self.assertRaises(ValueError):
                MODULE.calculate({"expression": expr})

if __name__ == "__main__":
    unittest.main()
