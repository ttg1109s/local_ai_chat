import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from python_worker_lib.physics import physics_calculate


class PhysicsCalculateTests(unittest.TestCase):
    def test_formula_catalog(self):
        result = physics_calculate({"list_formulas": True})
        self.assertEqual(result["formula_count"], 145)
        self.assertTrue(any(item["id"] == "force" for item in result["formulas"]))
        self.assertTrue(any(item["id"] == "constant_gravity" for item in result["formulas"]))

    def test_force_formula(self):
        result = physics_calculate({
            "formula_id": "force",
            "values": {"mass": 2, "acceleration": 3},
        })
        self.assertEqual(result["result"], 6.0)
        self.assertEqual(result["output_unit"], "N")

    def test_formula_input_unit_conversion(self):
        result = physics_calculate({
            "formula_id": "force",
            "values": {"mass": 500, "acceleration": 2},
            "units": {"mass": "g"},
        })
        self.assertAlmostEqual(result["result"], 1.0)
        self.assertEqual(result["inputs_si"]["mass"], 0.5)

    def test_formula_uses_math_pi_and_safe_functions(self):
        result = physics_calculate({
            "formula_id": "projectile_range",
            "values": {"initial_speed": 10, "angle": 45, "gravity": 9.80665},
        })
        self.assertAlmostEqual(result["result"], 10.197162129779283, places=8)

    def test_unknown_formula_and_bad_unit_rejected(self):
        with self.assertRaises(ValueError):
            physics_calculate({"formula_id": "not_a_formula", "values": {}})
        with self.assertRaises(ValueError):
            physics_calculate({
                "formula_id": "force",
                "values": {"mass": 1, "acceleration": 2},
                "units": {"mass": "stone"},
            })

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


if __name__ == "__main__":
    unittest.main()
