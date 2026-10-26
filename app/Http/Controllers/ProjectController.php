<?php
namespace App\Http\Controllers;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
class ProjectController
{
    public function index() { return Inertia::render('Dashboard', ['projects' => Project::withCount('media')->latest('updated_at')->get()]); }
    public function store(Request $request) {
        $project = Project::create($this->validated($request));
        return to_route('projects.show', $project)->with('success', 'Project created.');
    }
    public function show(Project $project) { return Inertia::render('Project', ['project' => $project->load('media')]); }
    public function update(Request $request, Project $project) {
        $project->update($this->validated($request));
        return back()->with('success', 'Project updated.');
    }
    public function destroy(Project $project) {
        // Files first: a failed disk operation leaves the records available for retry.
        \Illuminate\Support\Facades\DB::transaction(function () use ($project) {
            \App\Services\RemixFiles::removeForMedia($project->media->modelKeys());
            foreach ($project->media as $media) { if (!$media->isInboxSource()) Storage::delete($media->path); }
            $project->delete();
        });
        return to_route('dashboard')->with('success', 'Project deleted.');
    }
    private function validated(Request $request): array {
        return $request->validate(['name' => 'required|string|max:120', 'description' => 'nullable|string|max:2000']);
    }
}
