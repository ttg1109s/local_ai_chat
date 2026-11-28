import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from python_worker_lib.physics import physics_calculate


class PhysicsCalculateTests(unittest.TestCase):
    def test_formula_catalog_has_canonical_unit_metadata(self):
        result = physics_calculate({"list_formulas": True})
        self.assertEqual(result["formula_count"], 145)
        formulas = result["formulas"]
        force = next(item for item in formulas if item["id"] == "force")
        self.assertEqual(force["input_units"], {"mass": "kg", "acceleration": "m/s2"})
        self.assertEqual(force["output_unit"], "N")
        self.assertEqual(force["output_dimension"], "force")

    def test_force_formula_and_normalized_result_metadata(self):
        result = physics_calculate({
            "formula_id": "force",
            "values": {"mass": 2, "acceleration": 3},
        })
        self.assertEqual(result["result"], 6.0)
        self.assertEqual(result["to_unit"], "N")
        self.assertEqual(result["dimension"], "force")
        self.assertEqual(result["inputs"], {"mass": 2.0, "acceleration": 3.0})
        self.assertNotIn("output_unit", result)
        self.assertNotIn("inputs_si", result)

    def test_model_normalizes_inputs_before_tool_call(self):
        result = physics_calculate({
            "formula_id": "force",
            "values": {"mass": 0.5, "acceleration": 2},
        })
        self.assertEqual(result["result"], 1.0)
        self.assertEqual(result["to_unit"], "N")

    def test_temperature_conversion_formula_output_units(self):
        c_to_f = physics_calculate({"formula_id": "celsius_fahrenheit", "values": {"celsius": 20}})
        self.assertEqual(c_to_f["result"], 68.0)
        self.assertEqual(c_to_f["to_unit"], "degF")
        self.assertEqual(c_to_f["dimension"], "temperature")

    def test_formula_uses_math_pi_and_safe_functions(self):
        result = physics_calculate({
            "formula_id": "projectile_range",
            "values": {"initial_speed": 10, "angle": 45, "gravity": 9.80665},
        })
        self.assertAlmostEqual(result["result"], 10.197162129779283, places=8)
        self.assertEqual(result["to_unit"], "m")

    def test_unknown_formula_and_invalid_numeric_rejected(self):
        with self.assertRaises(ValueError):
            physics_calculate({"formula_id": "not_a_formula", "values": {}})
        with self.assertRaises(ValueError):
            physics_calculate({"formula_id": "force", "values": {"mass": float("nan"), "acceleration": 2}})

    def test_worker_dispatch_action(self):
        worker = ROOT / "tools" / "python_worker.py"
        payload = {"action": "physics_calculate", "arguments": {
            "formula_id": "force", "values": {"mass": 4, "acceleration": 2}
        }}
        proc = subprocess.run(
            [sys.executable, str(worker)],
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=True,
            timeout=15,
        )
        response = json.loads(proc.stdout)
        self.assertTrue(response["success"], response.get("error"))
        self.assertEqual(response["result"]["result"], 8.0)
        self.assertEqual(response["result"]["to_unit"], "N")


if __name__ == "__main__":
    unittest.main()
