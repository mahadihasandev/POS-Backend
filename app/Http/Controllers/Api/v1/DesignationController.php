<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Models\Designation;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignationController extends BaseApiController
{
    /**
     * List all designations with their assigned permissions and user count.
     */
    public function index(): JsonResponse
    {
        $designations = Designation::with(['permissions'])->withCount('users')->get();
        return $this->successResponse($designations);
    }

    /**
     * Create a new designation / designation role.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|unique:designations,slug',
            'description' => 'nullable|string',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $designation = Designation::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
        ]);

        if (!empty($validated['permission_ids'])) {
            $designation->permissions()->sync($validated['permission_ids']);
        }

        $designation->load(['permissions']);

        return $this->successResponse($designation, 'Designation created successfully.');
    }

    /**
     * Grant or take away permissions from a designation.
     * Admin can toggle any permission on/off!
     */
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $designation = Designation::findOrFail($id);

        $validated = $request->validate([
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $designation->permissions()->sync($validated['permission_ids']);
        $designation->load(['permissions']);

        return $this->successResponse(
            $designation,
            "Permissions for designation '{$designation->name}' updated successfully."
        );
    }

    /**
     * Get all system permissions grouped by module.
     */
    public function getAllPermissions(): JsonResponse
    {
        $permissions = Permission::all()->groupBy('module');
        return $this->successResponse($permissions);
    }

    /**
     * List users and their designated roles.
     */
    public function getUsers(): JsonResponse
    {
        $users = User::with(['designation.permissions'])->select('id', 'name', 'email', 'designation_id', 'created_at')->get();
        return $this->successResponse($users);
    }

    /**
     * Assign or change a user's designation.
     */
    public function updateUserDesignation(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'designation_id' => 'required|exists:designations,id',
        ]);

        $user->update(['designation_id' => $validated['designation_id']]);
        $user->load(['designation.permissions']);

        return $this->successResponse($user, "Designation assigned to user '{$user->name}' successfully.");
    }
}
