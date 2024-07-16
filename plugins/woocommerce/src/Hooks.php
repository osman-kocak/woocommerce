<?php

namespace Automattic\WooCommerce;

use Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator;
use Automattic\WooCommerce\Internal\Traits\AccessiblePrivateMethods;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Utilities\ArrayUtil;
use Automattic\WooCommerce\Utilities\StringUtil;

class Hooks {
	use AccessiblePrivateMethods;

	private static $container;

    private static $legacy_proxy;

	private static $built_in_hookings = [
		DataRegenerator::class => [
			['woocommerce_debug_tools', 'add_initiate_regeneration_entry_to_tools_array', 999],
			['woocommerce_run_product_attribute_lookup_regeneration_callback', 'run_regeneration_step_callback'],
			['woocommerce_installed', 'run_woocommerce_installed_callback']
		]
	];

    /**
     * Currently active hookings.
     *
     * Format: {hook_name}__{priority}__{accepted_args} => [target, method, priority, accepted_args]
     *
     * where target can be:
     *
     * - An object
     * - A callable that returns an object
     *   (runs once and then gets replaced with the class name of the returned object)
     * - A class name
     *
     * @var array
     */
	private static $active_hookings = [];

    /**
     * Cache of classes instantiated when the target passed to register_filter/action is a callable or a class name.
     * Classes are instantiated when the first time one of the hooks for which they are the target is executed,
     * and then reused for any further hook execution.
     *
     * Format: class_name => class_instance
     *
     * @var array
     */
	private static $class_instances = [];

    private static $hookings_count_by_class = [];

	public static function init() {
		self::$container = wc_get_container();
        self::$legacy_proxy = self::$container->get(LegacyProxy::class);

		self::mark_static_method_as_accessible('handle_hook');

		foreach(self::$built_in_hookings as $class_name => $hookings) {
            foreach($hookings as $hooking) {
                self::register_hook_core($hooking[0], $class_name, $hooking[1], $hooking[2] ?? 10, $hooking[3] ?? 1);
            }
		}
	}

	public static function register_action(string $hook_name, $target, string $method, int $priority = 10, int $accepted_args = 1) {
		return self::register_filter($hook_name, $target, $method, $priority, $accepted_args);
	}

	public static function register_filter(string $hook_name, $target, string $method, int $priority = 10, int $accepted_args = 1) {
		if(!is_callable($target) && !is_object($target) && !is_string($target)) {
			throw new \InvalidArgumentException("\$target must be either an object, a callable that returns an instance of an object, or a class name.");
		}

		return self::register_hook_core($hook_name, $target, $method, $priority, $accepted_args);
	}

	private static function register_hook_core(string $hook_name, $target, $method, $priority = 10, $accepted_args = 1) {
		$hooks_array_key = "{$hook_name}__{$priority}__{$accepted_args}";
		if(!isset(self::$active_hookings[$hooks_array_key])) {
			add_filter($hook_name, fn() => self::handle_hook($hook_name, $priority, $accepted_args, ...func_get_args()), $priority, $accepted_args);
		}

        if(is_string($target)) {
            self::increase_hookings_count_for_class($target);
        }

        $hooking_id = bin2hex(random_bytes(16));
		self::$active_hookings[$hooks_array_key][] = [$target, $method, $priority, $accepted_args, $hooking_id];
        return $hooking_id;
	}

    public static function remove_action_by_id(string $hook_id): bool {
        return self::remove_filter_by_id($hook_id);
    }

    public static function remove_filter_by_id(string $hook_id): bool {
        foreach(self::$active_hookings as $hooks_array_key => &$hookings_info) {
            foreach($hookings_info as $info_key => $hooking_info) {
                if($hooking_info[4] !== $hook_id) {
                    continue;
                }

                self::remove_hook_core($hooks_array_key, $info_key, $hooking_info[0]);
                return true;
            }
        }

        return false;
    }

    public static function remove_action(string $hook_name, $target, string $method, int $priority = 10, int $accepted_args = 1) {
        return self::remove_filter($hook_name, $target, $method, $priority, $accepted_args);
    }

    public static function remove_filter(string $hook_name, $target, string $method, int $priority = 10, int $accepted_args = 1) {
        $hooks_array_key = "{$hook_name}__{$priority}__{$accepted_args}";
        $hookings_info = self::$active_hookings[$hooks_array_key] ?? null;
        if(is_null($hookings_info)) {
            return false;
        }

        foreach($hookings_info as $info_key => $hooking_info) {
            if($hooking_info[0] !== $target || $hooking_info[1] !== $method || $hooking_info[2] !== $priority || $hooking_info[3] !== $accepted_args) {
                continue;
            }

            self::remove_hook_core($hooks_array_key, $info_key, $hooking_info[0]);
            return true;
        }

        return false;
    }

    private static function remove_hook_core($hooks_array_key, $info_key, $target) {
        unset(self::$active_hookings[$hooks_array_key][$info_key]);
        if(empty(self::$active_hookings[$hooks_array_key])) {
            unset(self::$active_hookings[$hooks_array_key]);
        }

        if(!is_string($target)) {
            return;
        }

        $hookings_count = self::$hookings_count_by_class[$target] ?? 0;
        if($hookings_count > 1) {
            self::$hookings_count_by_class[$target]--;
        }
        else {
            unset(self::$class_instances[$target]);
            unset(self::$hookings_count_by_class[$target]);
        }
    }

    public static function remove_all_hooks($hook_name) {
        $removed = 0;
        $matching_hook_keys = array_filter(array_keys(self::$active_hookings), fn($key) => StringUtil::starts_with($key, $hook_name . '__'));
        foreach($matching_hook_keys as $hook_key) {
            foreach(self::$active_hookings[$hook_key] as $hooking_info) {
                self::remove_filter_by_id($hooking_info[4]);
                $removed++;
            }
        }
        return $removed;
    }

	private static function handle_hook($hook_name, $priority, $accepted_args, ...$args) {
		$hooks_array_key = "{$hook_name}__{$priority}__{$accepted_args}";
        $value = $args[0] ?? null;

		$hook_info = &self::$active_hookings[$hooks_array_key];
        if(is_null($hook_info)) {
            //This will happen if all the hooks of the same name, priority and args count have been removed.
            return $value;
        }

		foreach($hook_info as &$hook_instance) {
			$target = $hook_instance[0];
            $method = $hook_instance[1];
            $is_static = StringUtil::contains($method, '::');

            // "if"s ordering matters: a callable is also an object!
            if($is_static) {
                if(is_callable($target)) {
                    $target();
                    $hook_instance[0] = null;
                }
            }
            elseif(is_callable($target)) {
				$instance = $target();
				$class_name = get_class($instance);
				self::$class_instances[$class_name] = $instance;
                $hook_instance[0] = $class_name;
                self::increase_hookings_count_for_class($class_name);
			}
            elseif(is_object($target)) {
                $instance = $target;
            }
			elseif(isset(self::$class_instances[$target])) {
				$instance = self::$class_instances[$target];
			}
			else {
				$instance = self::$container->has($target) ? self::$container->get($target) : self::$legacy_proxy->get_instance_of($target);
				self::$class_instances[$target] = $instance;
			}

			$value = $is_static ? $method(...$args) : $instance->$method(...$args);
            if(!empty($args)) {
                $args[0] = $value;
            }
		}

		return $value;
	}

    private static function increase_hookings_count_for_class($class_name) {
        $count = self::$hookings_count_by_class[$class_name] ?? 0;
        self::$hookings_count_by_class[$class_name] = $count + 1;
    }
}
