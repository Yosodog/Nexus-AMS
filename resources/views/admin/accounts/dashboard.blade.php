@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6"><h3 class="mb-0">Accounts</h3></div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">All Accounts</h3>
                </div>
                <div class="card-body">
                    <div id="all_accounts">
                        <div class="row">
                            <div class="col-sm-12 col-md-6"></div>
                            <div class="col-sm-12 col-md-6"></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="overflow-x-auto">
                                    <table id="account_table" class="table table-bordered table-hover">
                                        <thead>
                                        <tr>
                                            <th>Nation ID</th>
                                            <th>Owner</th>
                                            <th>Name</th>
                                            @foreach(\App\Services\PWHelperService::resources() as $resource)
                                                <th>{{ ucfirst($resource) }}</th>
                                            @endforeach
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($accounts as $acc)
                                            <tr>
                                                <td><a href="https://politicsandwar.com/nation/id={{ $acc->nation_id }}"
                                                       target="_blank">{{ $acc->nation_id }}</a></td>
                                                <td><a href="#">{{ $acc->user->name ?? "Deleted Account" }}</a></td>
                                                <td>
                                                    <a href="{{ route("admin.accounts.view", $acc->id) }}">{{ $acc->name }}</a>
                                                </td>
                                                <td>${{ number_format($acc->money, 2) }}</td>
                                                @foreach(\App\Services\PWHelperService::resources(false) as $resource)
                                                    <td>{{ number_format($acc->$resource, 2) }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endsection

                @section("scripts")
                    <script>
                        $(document).ready(function () {
                            $('#account_table').DataTable();
                        });
                    </script>
@endsection
