<?php

namespace Tuli;

use PHPCfg\Operand;
use PHPCfg\Op;
use Gliph\Graph\DirectedAdjacencyList;

class TypeResolver {

	protected $components;

	public function resolve(array $components) {
		$this->components = $components;
		$resolved = new \SplObjectStorage;
		$unresolved = new \SplObjectStorage;
		foreach ($components['variables'] as $op) {
			if ($op instanceof Operand\Literal) {
				$resolved[$op] = Type::fromValue($op->value);
			} else {
				$unresolved[$op] = new Type(Type::TYPE_UNKNOWN);
			}
		}

		if (count($unresolved) === 0) {
			// short-circuit
			return;
		}
		$round = 1;
		do {
			echo "Round " . $round++ . " (" . count($unresolved) . " unresolved variables out of " . count($components['variables']) . ")\n";
			$start = round(count($resolved) / count($unresolved), 6);
			$i = 0;
			$toRemove = [];
			foreach ($unresolved as $k => $var) {
				$i++;
				if ($i % 10 === 0) {
					echo ".";
				}
				if ($i % 800 === 0) {
					echo "\n";
				}
				$type = $this->resolveVar($var, $resolved);
				if ($type) {
					$toRemove[] = $var;
					$resolved[$var] = $type;
				}
			}
			foreach ($toRemove as $remove) {
				$unresolved->detach($remove);
			}
			echo "\n";
		} while (count($unresolved) > 0 && $start < round(count($resolved) / count($unresolved), 6));
		foreach ($resolved as $var) {
			$var->type = $resolved[$var];
		}
		foreach ($unresolved as $var) {
			$var->type = $unresolved[$var];
		}
	}

	protected function resolveVar(Operand $var, \SplObjectStorage $resolved) {
		$types = [];
		foreach ($var->dag->predecessorsOf($var) as $prev) {
			$type = $this->resolveVarOp($var, $prev, $resolved);
			if ($type) {
				foreach ($type as $t) {
					$types[] = $t;
				}
			} else {
				return false;
			}
		}
		if (empty($types)) {
			return false;
		}
		$same = null;
		foreach ($types as $type) {
			if (is_null($same)) {
				$same = $type;
			} elseif (!$same->equals($type)) {
				// Type changes with different call paths!
				return false;
			}
		}
		return $same;
	}

	protected function resolveVarOp(Operand $var, Op $op, \SplObjectStorage $resolved) {
		switch ($op->getType()) {
			case 'Expr_Array':
			case 'Expr_Cast_Array':
				// Todo: determine subtypes better
				return [new Type(Type::TYPE_ARRAY, new Type(Type::TYPE_MIXED))];
			case 'Expr_ArrayDimFetch':
				if ($resolved->contains($op->var)) {
					// Todo: determine subtypes better
					return [new Type(Type::TYPE_MIXED)];
				}
				break;
			case 'Expr_Assign':
			case 'Expr_AssignRef':
				if ($resolved->contains($op->expr)) {
					return [$resolved[$op->expr]];
				}
				break;
			case 'Expr_BinaryOp_Equal':
			case 'Expr_BinaryOp_NotEqual':
			case 'Expr_BinaryOp_Greater':
			case 'Expr_BinaryOp_GreaterOrEqual':
			case 'Expr_BinaryOp_Identical':
			case 'Expr_BinaryOp_NotIdentical':
			case 'Expr_BinaryOp_Smaller':
			case 'Expr_BinaryOp_SmallerOrEqual':
			case 'Expr_BinaryOp_LogicalAnd':
			case 'Expr_BinaryOp_LogicalOr':
			case 'Expr_BinaryOp_LogicalXor':
			case 'Expr_BooleanNot':
			case 'Expr_Cast_Bool':
			case 'Expr_Empty':
			case 'Expr_InstanceOf':
			case 'Expr_Isset':
				return [new Type(Type::TYPE_BOOLEAN)];
			case 'Expr_BinaryOp_BitwiseAnd':
			case 'Expr_BinaryOp_BitwiseOr':
			case 'Expr_BinaryOp_BitwiseXor':
				if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
					switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
						case [Type::TYPE_STRING, Type::TYPE_STRING]:
							return [new Type(Type::TYPE_STRING)];
						default:
							return [new Type(Type::TYPE_LONG)];
					}
				}
				break;
			case 'Expr_BinaryOp_Div':
			case 'Expr_BinaryOp_Plus':
			case 'Expr_BinaryOp_Minus':
			case 'Expr_BinaryOp_Mul':
				if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
					switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
						case [Type::TYPE_LONG, Type::TYPE_LONG]:
							return [new Type(Type::TYPE_LONG)];
						case [Type::TYPE_DOUBLE, TYPE::TYPE_LONG]:
						case [Type::TYPE_LONG, TYPE::TYPE_DOUBLE]:
						case [Type::TYPE_DOUBLE, TYPE::TYPE_DOUBLE]:
							return [new Type(Type::TYPE_DOUBLE)];
						case [Type::TYPE_ARRAY, Type::TYPE_ARRAY]:
							if ($resolved[$op->left]->subType->equals($resolved[$op->right]->subType)) {
								return [new Type(Type::TYPE_ARRAY, $resolved[$op->left]->subType)];
							}
							// TODO: handle the int->float widening case
							return [new Type(Type::TYPE_ARRAY, new Type(Type::TYPE_MIXED))];
						default:
							throw new \RuntimeException("Unknown Type Pair: " . $resolved[$op->left]->type . ":" . $resolved[$op->right]->type);
					}
				}

				break;
			case 'Expr_BinaryOp_Concat':
			case 'Expr_Cast_String':
			case 'Expr_ConcatList':
				return [new Type(Type::TYPE_STRING)];
			case 'Expr_BinaryOp_Mod':
			case 'Expr_Print':
				return [new Type(Type::TYPE_LONG)];
			case 'Expr_Clone':
				if ($resolved->contains($op->expr)) {
					return [$resolved[$op->expr]];
				}
				break;
			case 'Expr_Closure':
				return [new Type(Type::TYPE_USER, null, "Closure")];
			case 'Expr_FuncCall':
				if ($op->name instanceof Operand\Literal) {
					$name = strtolower($op->name->value);
					if (isset($this->components['functionLookup'][$name])) {
						$result = [];
						foreach ($this->components['functionLookup'][$name] as $func) {
							if ($func->returnType) {
								$result[] = Type::fromDecl($func->returnType->value);
							} else {
								$result[] = new Type(Type::TYPE_MIXED);
							}
						}
						return $result;
					}
				}
				break;
			case 'Expr_New':
				if ($op->class instanceof Operand\Literal) {
					return [new Type(Type::TYPE_USER, null, $op->class->value)];
				}
				return [new Type(Type::TYPE_OBJECT)];
			case 'Expr_Param':
				if ($op->type) {
					return [Type::fromDecl($op->type->value)];
				}
				return [new Type(Type::TYPE_MIXED)];
			case 'Expr_Yield':
			case 'Expr_Include':
			case 'Expr_PropertyFetch':
			case 'Expr_StaticPropertyFetch':
				// TODO: we may be able to determine these...
				return [new Type(Type::TYPE_MIXED)];
			case 'Expr_UnaryMinus':
			case 'Expr_UnaryPlus':
				if ($resolved->contains($op->expr)) {
					switch ($resolved[$op->expr]->type) {
						case Type::TYPE_LONG:
						case Type::TYPE_DOUBLE:
							return [$resolved[$op->expr]->type];
					}
					return [new Type(Type::TYPE_NUMERIC)];
				}
				break;

			case 'Expr_Eval':
				return [new Type(Type::TYPE_MIXED)];
			case 'Iterator_Key':
				if ($resolved->contains($op->var)) {
					// TODO: implement this as well
					return [new Type(Type::TYPE_MIXED)];
				}
				break;
			case 'Expr_Exit':
			case 'Iterator_Reset':
				return [new Type(Type::TYPE_VOID)];
			case 'Iterator_Valid':
				return [new Type(Type::TYPE_BOOLEAN)];
			case 'Iterator_Value':
				if ($resolved->contains($op->var)) {
					if ($resolved[$op->var]->subType) {
						return [Type::fromDecl($resolved[$op->var]->subType)];
					}
					return [new Type(Type::TYPE_MIXED)];
				}
				break;
			case 'Expr_ConstFetch':
			case 'Expr_MethodCall':
			case 'Expr_StaticCall':
			case 'Expr_ClassConstFetch':
				//TODO
				return false;
			default:
				throw new \RuntimeException("Unknown operand prefix type: " . $op->getType());
		}
		return false;
	}

}